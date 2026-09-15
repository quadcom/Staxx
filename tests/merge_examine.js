/* StaXX — tests for the merge examiner (PLAN_148 phase 2).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/merge_examine.js
 *
 * No framework, no npm, no network — same shape as tests/health_offer.js:
 * one line per case, non-zero exit if anything fails.
 *
 * merge-examine.js takes an already-digested "stack descriptor" (see its
 * own header), not raw compose text — so this file is also the one place
 * that turns a real fixture pair under tests/fixtures/merge-pairs/ into
 * that shape, using compose-model.js's own parser rather than a second
 * YAML reader. That adapter is test-only; the wizard will build its own
 * descriptors from whatever it already has parsed.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');
var M = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-examine.js');
// Phase 4 — the text splice that turns a decided merge into real file
// bytes. Its own descriptor adapter lives inside merge-write.js itself
// (production code, not test-only like loadStack() below), so these cases
// only need the RAW files off disk.
var MW = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/merge-write.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

/* =========================================================================
 * The adapter: real compose.yaml (+ .env, + companion files) -> descriptor
 * =========================================================================*/

function toPlain(node) {
  if (!node) return undefined;
  if (node.kind === 'scalar') return node.value;
  if (node.kind === 'seq') return node.items.map(function (it) { return toPlain(it.value); });
  if (node.kind === 'map') {
    var o = {};
    node.keys.forEach(function (k) { o[k] = toPlain(node.pairs[k].value); });
    return o;
  }
  return undefined;   // anchors/aliases/flow/etc — not needed by these fixtures
}

function asStringArray(v) {
  if (v == null) return [];
  return Array.isArray(v) ? v.slice() : [v];
}

function splitPortEntry(entry) {
  // "8091:80", "8091:80/tcp", or a bare "80" (no host side published).
  var text = String(entry);
  var proto = 'tcp';
  var protoMatch = /\/(tcp|udp)$/.exec(text);
  if (protoMatch) { proto = protoMatch[1]; text = text.slice(0, -protoMatch[0].length); }
  var parts = text.split(':');
  if (parts.length >= 2) return { host: parts[0], container: parts[1], protocol: proto };
  return { host: '', container: parts[0], protocol: proto };
}

function readEnvFile(file) {
  if (!fs.existsSync(file)) return null;
  var lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
  if (lines.length && lines[lines.length - 1] === '') lines.pop();   // trailing newline, not a blank line
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

function loadStack(dir, name) {
  var composeText = fs.readFileSync(path.join(dir, 'compose.yaml'), 'utf8');
  var doc = CM.parse(composeText);
  var plain = toPlain(doc.root) || {};

  var services = {};
  Object.keys(plain.services || {}).forEach(function (svcName) {
    var raw = plain.services[svcName] || {};
    var volumes = asStringArray(raw.volumes).map(function (entry) {
      if (typeof entry !== 'string') return null;
      var parts = entry.split(':');
      var source = parts[0];
      var target = parts[1] || '';
      var type = 'bind';
      if (source.charAt(0) === '.' || source.charAt(0) === '/') type = 'bind';
      else type = 'named';
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

  var files = fs.readdirSync(dir).filter(function (f) { return f !== 'compose.yaml' && f !== '.env'; });

  return {
    name: name,
    files: files,
    env: readEnvFile(path.join(dir, '.env')),
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

// The raw form buildMergedText() actually wants — text off disk, not a
// pre-digested descriptor; it builds its own via merge-write.js's own
// descriptorFromText(), so there is no second adapter to keep in step here.
function loadRaw(fixtureName, side) {
  var dir = path.join(__dirname, 'fixtures', 'merge-pairs', fixtureName, side);
  var text = fs.readFileSync(path.join(dir, 'compose.yaml'), 'utf8');
  var envPath = path.join(dir, '.env');
  var envText = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : null;
  var files = fs.readdirSync(dir).filter(function (f) { return f !== 'compose.yaml' && f !== '.env'; });
  return { name: side, text: text, envText: envText, files: files };
}

function loadPair(fixtureName) {
  var base = path.join(__dirname, 'fixtures', 'merge-pairs', fixtureName);
  return {
    host: loadStack(path.join(base, 'host'), 'host'),
    incoming: loadStack(path.join(base, 'incoming'), 'incoming')
  };
}

function findingsOf(result, kind) {
  return result.findings.filter(function (f) { return f.kind === kind; });
}

/* =========================================================================
 * The parse check every buildMergedText() case below is put through —
 * PLAN_148's own defect: 92 cases matched STRINGS in the merged text and not
 * one of them PARSED it, which is exactly how an unindented synthesised
 * block (a volume name that escaped the volumes: mapping entirely) passed
 * every existing check while being invalid YAML. This reparses the text
 * with compose-model.js — the repository's own parser, so nothing new is
 * added to prove it — and checks the shape a real `docker compose up` would
 * insist on: no warnings, no stray top-level key, every service this fixture
 * is meant to carry actually present, and every named volume a service
 * mounts actually declared under volumes: (a bind mount or an empty source
 * is not a name to look up, so those are skipped).
 * ========================================================================= */

var MERGED_TOP_KEYS = { services: 1, volumes: 1, networks: 1, configs: 1, secrets: 1, 'x-unraid': 1 };

function assertMergedIsValid(label, composeText, expectedServiceNames) {
  var reparsed = CM.parse(composeText);
  ok(label + ' — the merged file parses clean, no warnings',
     reparsed.warnings.length === 0, reparsed.warnings.join('; '));

  var plain = toPlain(reparsed.root) || {};
  var stray = Object.keys(plain).filter(function (k) { return !MERGED_TOP_KEYS[k]; });
  ok(label + ' — no unexpected top-level key appears', stray.length === 0, JSON.stringify(stray));

  var services = plain.services || {};
  ok(label + ' — services holds exactly what is expected',
     Object.keys(services).sort().join(',') === expectedServiceNames.slice().sort().join(','),
     'got: ' + Object.keys(services).join(','));

  var declaredVolumes = plain.volumes || {};
  Object.keys(services).forEach(function (svcName) {
    var vols = services[svcName] && services[svcName].volumes;
    (Array.isArray(vols) ? vols : []).forEach(function (entry) {
      if (typeof entry !== 'string') return;
      var source = entry.split(':')[0];
      if (source === '' || source.charAt(0) === '.' || source.charAt(0) === '/') return;   // bind mount
      ok(label + ' — named volume "' + source + '" (mounted by ' + svcName + ') is declared under volumes:',
         Object.prototype.hasOwnProperty.call(declaredVolumes, source),
         'declared: ' + JSON.stringify(Object.keys(declaredVolumes)));
    });
  });

  return plain;
}

/* =========================================================================
 * A. Container / service name clashes
 * ========================================================================= */

console.log('\nA. Container name clashes');

(function () {
  var pair = loadPair('container-name-clash');
  var r = M.examine(pair.host, [pair.incoming]);

  var svcClash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('the clashing service key is renamed with the incoming stack\'s name as suffix',
     svcClash.length === 1 && svcClash[0].facts.from === 'web' && svcClash[0].facts.to === 'web_incoming');
  ok('the rename is automatic, not a decision', svcClash[0].severity === 'automatic');

  var cnClash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'container_name'; });
  ok('the explicit container_name clash is caught too', cnClash.length === 1 && cnClash[0].facts.to === 'web_incoming');

  var clean = findingsOf(r, 'clean').filter(function (f) { return f.facts.category === 'container names'; });
  ok('no "container names" clean line once one actually clashed', clean.length === 0);
})();

/* =========================================================================
 * B. Published port clashes
 * ========================================================================= */

console.log('\nB. Published port clashes');

(function () {
  var pair = loadPair('port-clash');
  var r = M.examine(pair.host, [pair.incoming]);

  var clash = findingsOf(r, 'port-clash');
  ok('one port clash is found', clash.length === 1);
  ok('it names the port and the service that already holds it',
     clash[0].facts.port === '8091' && clash[0].facts.heldBy === 'web' && clash[0].facts.service === 'db');
  ok('the recommended choice is a free port, listed first',
     clash[0].choices[0].id === 'free-port' && clash[0].choices[0].recommended === true);
  ok('stopping publication is offered as the alternative',
     clash[0].choices[1].id === 'stop-publishing' && clash[0].choices[1].recommended === false);
})();

/* =========================================================================
 * C. A shorthand both files use for different things
 * ========================================================================= */

console.log('\nC. Shorthand (top-level declaration) clashes');

(function () {
  var pair = loadPair('shorthand-clash');
  var r = M.examine(pair.host, [pair.incoming]);

  var clash = findingsOf(r, 'shorthand-clash');
  ok('the disagreeing "demo" network is found', clash.length === 1);
  ok('it names the kind and the rename', clash[0].facts.declKind === 'networks' &&
     clash[0].facts.from === 'demo' && clash[0].facts.to === 'demo_incoming');
  ok('the rename is automatic, not offered as a choice', clash[0].severity === 'automatic' && !clash[0].choices);

  // Two stacks declaring the exact same thing under the same name must NOT
  // be flagged — only a disagreement is a clash.
  var host2 = JSON.parse(JSON.stringify(pair.host));
  var incoming2 = JSON.parse(JSON.stringify(pair.incoming));
  incoming2.compose.networks.demo.def = host2.compose.networks.demo.def;   // now identical
  var r2 = M.examine(host2, [incoming2]);
  ok('an identical declaration under the same name is kept once, nothing renamed',
     findingsOf(r2, 'shorthand-clash').length === 0);
})();

/* =========================================================================
 * D. A relative path naming a file already in the host's folder
 * ========================================================================= */

console.log('\nD. Files beside the stack — a name already in use');

(function () {
  var pair = loadPair('relative-path-clash');
  var r = M.examine(pair.host, [pair.incoming]);

  var refusal = r.findings.filter(function (f) { return f.kind === 'file-clash' && f.severity === 'refusal'; });
  ok('the "config" folder is refused, naming the file', refusal.length === 1 && refusal[0].facts.name === 'config');
  ok('refusals come first in the finding list', r.findings[0].kind === 'file-clash' && r.findings[0].severity === 'refusal');

  // The clean path: no collision at all.
  var host2 = { name: 'host', files: [], env: null, compose: pair.host.compose };
  var incoming2 = pair.incoming;
  var r2 = M.examine(host2, [incoming2]);
  ok('no refusal once the host folder does not already hold that name',
     r2.findings.filter(function (f) { return f.kind === 'file-clash' && f.severity === 'refusal'; }).length === 0);
  ok('it is counted as clean instead',
     r2.findings.some(function (f) { return f.kind === 'file-clash' && f.severity === 'clean' && f.facts.name === 'config'; }));
})();

/* =========================================================================
 * E. Two disagreeing .env files
 * ========================================================================= */

console.log('\nE. The two settings lists (.env)');

(function () {
  var pair = loadPair('env-clash');
  var r = M.examine(pair.host, [pair.incoming], { date: '2026-09-14' });

  var join = findingsOf(r, 'settings-join');
  ok('one settings-join finding for the incoming stack', join.length === 1);
  var f = join[0].facts;

  ok('TZ (same value both sides) is written once, not repeated', f.sameValueNames.indexOf('TZ') >= 0);
  ok('DB_PASSWORD (different value) is renamed with the stack name appended',
     f.renamed.length === 1 && f.renamed[0].from === 'DB_PASSWORD' && f.renamed[0].to === 'DB_PASSWORD_INCOMING');

  var names = f.joinedLines.filter(function (l) { return l.type === 'setting'; }).map(function (l) { return l.name; });
  var settingLines = f.joinedLines.filter(function (l) { return l.type === 'setting'; });
  ok('the host\'s TZ survives untouched and first', settingLines[0].name === 'TZ' && settingLines[0].value === 'Europe/London');
  ok('the joined file carries the host\'s DB_PASSWORD unchanged', names.indexOf('DB_PASSWORD') >= 0);
  ok('and the renamed incoming one alongside it', names.indexOf('DB_PASSWORD_INCOMING') >= 0);
  ok('a name only the incoming side has (MYSQL_ROOT_PASSWORD) arrives untouched', names.indexOf('MYSQL_ROOT_PASSWORD') >= 0);

  var heading = f.joinedLines.filter(function (l) {
    return l.type === 'comment' && /folded in from incoming, 2026-09-14/.test(l.text);
  });
  ok('the incoming block carries the required heading, with the stack name and date', heading.length === 1);

  ok('the recommended choice is to accept the join, listed first',
     join[0].choices[0].id === 'accept-join' && join[0].choices[0].recommended === true);
  ok('"Stop here" is always the alternative', join[0].choices[1].id === 'stop-here');

  // Nothing to join when the incoming stack has no .env at all.
  var bare = { name: 'bare', files: [], env: null, compose: pair.incoming.compose };
  var r2 = M.examine(pair.host, [bare]);
  ok('no settings-join finding when the incoming stack has no .env', findingsOf(r2, 'settings-join').length === 0);
})();

/* =========================================================================
 * F. Storage Docker manages
 * ========================================================================= */

console.log('\nF. Storage Docker manages');

(function () {
  var pair = loadPair('storage-volume');
  var r = M.examine(pair.host, [pair.incoming]);

  var storage = findingsOf(r, 'storage-volume');
  ok('the named volume actually mounted is found', storage.length === 1);
  ok('it names the real name today and the name it would go looking for',
     storage[0].facts.oldName === 'incoming_dbdata' && storage[0].facts.newName === 'host_dbdata');
  ok('"go on using the existing storage" is recommended and first', storage[0].choices[0].id === 'keep-existing' &&
     storage[0].choices[0].recommended === true);
  ok('"start empty" and "stop here" follow, in that order',
     storage[0].choices[1].id === 'start-empty' && storage[0].choices[2].id === 'stop-here');

  // An already-external volume never changes identity, so it is not raised.
  var extIncoming = JSON.parse(JSON.stringify(pair.incoming));
  extIncoming.compose.volumes.dbdata.external = true;
  var r2 = M.examine(pair.host, [extIncoming]);
  ok('an external volume is never flagged — its name never changes', findingsOf(r2, 'storage-volume').length === 0);

  // A declared-but-unused volume raises nothing either — there is nothing to lose.
  var unused = JSON.parse(JSON.stringify(pair.incoming));
  unused.compose.services.db.volumes = [];
  var r3 = M.examine(pair.host, [unused]);
  ok('a declared volume nothing mounts is not flagged', findingsOf(r3, 'storage-volume').length === 0);
})();

/* =========================================================================
 * G. Step 4 wiring — an address that becomes a service name, and the
 * published port nothing needs any more
 * ========================================================================= */

console.log('\nG. Wiring');

(function () {
  var pair = loadPair('wiring');
  var r = M.examine(pair.host, [pair.incoming]);

  var rewire = findingsOf(r, 'address-rewire');
  ok('one address is found that can become a service name', rewire.length === 1);
  ok('it names the service, the env var, and what it becomes',
     rewire[0].facts.service === 'web' && rewire[0].facts.envVar === 'DB_ADDRESS' &&
     rewire[0].facts.toService === 'db' && rewire[0].facts.toPort === '3306');
  ok('offered ticked by default', rewire[0].choices[0].ticked === true);

  var unneeded = findingsOf(r, 'port-unneeded');
  ok('the now-unneeded published port is offered', unneeded.length === 1 &&
     unneeded[0].facts.service === 'db' && unneeded[0].facts.port === '3307');
  ok('offered UNTICKED — something else on the network may still be using it',
     unneeded[0].choices[0].ticked === false);

  // An address StaXX cannot place with confidence is left alone, not guessed at.
  var uncertain = JSON.parse(JSON.stringify(pair.host));
  uncertain.compose.services.web.environment.DB_ADDRESS = '203.0.113.5:9999';
  var r2 = M.examine(uncertain, [pair.incoming]);
  ok('an address matching no published port in the merge is left alone, not rewritten',
     findingsOf(r2, 'address-rewire').length === 0 && findingsOf(r2, 'left-alone').length === 1);
  ok('the left-alone note says so in plain English',
     /not confidently|left as written/.test(findingsOf(r2, 'left-alone')[0].facts.note));
})();

/* =========================================================================
 * G2. A separate _HOST/_PORT pair — the plan's own headline case, which the
 * combined-value form above cannot see at all (DB_HOST and DB_PORT are two
 * env vars, not one "host:port" string). Correlated only when a common
 * prefix names exactly one of each AND the port's value matches a port
 * really published somewhere in the merge — never on the name alone.
 * ========================================================================= */

console.log('\nG2. A separate _HOST/_PORT pair');

(function () {
  // The address lives in the HOST stack, pointing at the incoming database —
  // the direction PLAN_148 itself describes: demo-web's DB_HOST/DB_PORT
  // pointing at demo-db's mariadb service.
  var pair = loadPair('wiring-split-host');
  var r = M.examine(pair.host, [pair.incoming]);

  var rewire = findingsOf(r, 'address-rewire');
  ok('the DB_HOST/DB_PORT pair is found and paired', rewire.length === 1);
  ok('it names the service, both halves of the pair, and what they become',
     rewire[0].facts.service === 'web' && rewire[0].facts.hostVar === 'DB_HOST' &&
     rewire[0].facts.portVar === 'DB_PORT' && rewire[0].facts.fromHost === '192.0.2.88' &&
     rewire[0].facts.fromPort === '3307' &&
     rewire[0].facts.toService === 'mariadb' && rewire[0].facts.toPort === '3306',
     JSON.stringify(rewire[0] && rewire[0].facts));
  ok('offered ticked by default', rewire[0].choices[0].ticked === true);
  ok('says plainly what confirmed it, since no server list was supplied',
     rewire[0].facts.matchedOn === 'port-evidence');

  var unneeded = findingsOf(r, 'port-unneeded');
  ok('the now-unneeded published port is raised', unneeded.length === 1 &&
     unneeded[0].facts.service === 'mariadb' && unneeded[0].facts.port === '3307');
  ok('offered UNTICKED', unneeded[0].choices[0].ticked === false);

  var w = MW.buildMergedText(loadRaw('wiring-split-host', 'host'), [loadRaw('wiring-split-host', 'incoming')],
    { date: '2026-09-14' });
  ok('rewritten to the real service name and its container-side port, by default',
     /DB_HOST: mariadb/.test(w.composeText) && /DB_PORT: "3306"/.test(w.composeText));
  // The published "3307:3306" ports: entry legitimately survives here —
  // port-unneeded is unticked by default — so only the address itself,
  // never the port number in general, is checked for having gone.
  ok('the old address is gone, not merely commented out',
     w.composeText.indexOf('192.0.2.88') === -1);
  assertMergedIsValid('wiring-split-host', w.composeText, ['web', 'mariadb']);
})();

(function () {
  // The same pair, the other way round: the INCOMING stack holds the
  // address, pointing back at the HOST's own published port. PLAN_148 is
  // explicit that this has to work whichever side holds the address.
  var pair = loadPair('wiring-split-incoming');
  var r = M.examine(pair.host, [pair.incoming]);

  var rewire = findingsOf(r, 'address-rewire');
  ok('found when the incoming stack holds the address instead', rewire.length === 1 &&
     rewire[0].stack === 'incoming');
  ok('still resolves to the host\'s own service and real port',
     rewire[0].facts.toService === 'mariadb' && rewire[0].facts.toPort === '3306');

  var w = MW.buildMergedText(loadRaw('wiring-split-incoming', 'host'), [loadRaw('wiring-split-incoming', 'incoming')],
    { date: '2026-09-14' });
  ok('rewritten inside the incoming service once it is spliced in',
     /DB_HOST: mariadb/.test(w.composeText) && /DB_PORT: "3306"/.test(w.composeText));
  assertMergedIsValid('wiring-split-incoming', w.composeText, ['web', 'mariadb']);
})();

(function () {
  // The port half names a port nothing in the merge actually publishes —
  // left alone, not guessed at, even though the names line up perfectly.
  var pair = loadPair('wiring-split-unmatched');
  var r = M.examine(pair.host, [pair.incoming]);

  ok('nothing is rewired when the port cannot be confirmed', findingsOf(r, 'address-rewire').length === 0);
  var alone = findingsOf(r, 'left-alone');
  ok('the _HOST variable is reported left alone', alone.length === 1 && alone[0].facts.envVar === 'DB_HOST');
  ok('the note says the port could not be confirmed',
     /port nothing in this merge publishes/.test(alone[0].facts.note));
})();

(function () {
  // Two host-ish names share one prefix (case is the only difference) —
  // exactly the one-to-many shape the plan rules out. Left alone for both,
  // never guessed at even though the port half matches cleanly.
  var pair = loadPair('wiring-split-ambiguous');
  var r = M.examine(pair.host, [pair.incoming]);

  ok('an ambiguous prefix match is never rewired', findingsOf(r, 'address-rewire').length === 0);
  var alone = findingsOf(r, 'left-alone');
  ok('both of the ambiguous names are left alone', alone.length === 2 &&
     alone.every(function (f) { return /too ambiguous to pair/.test(f.facts.note); }));
})();

/* =========================================================================
 * H. The clean path — nothing clashes at all
 * ========================================================================= */

console.log('\nH. Checked and fine, when nothing clashes');

(function () {
  var pair = loadPair('clean');
  var r = M.examine(pair.host, [pair.incoming]);

  ['container-name-clash', 'port-clash', 'shorthand-clash', 'file-clash'].forEach(function (kind) {
    ok('nothing of kind ' + kind + ' is raised', findingsOf(r, kind).filter(function (f) {
      return f.severity !== 'clean';
    }).length === 0);
  });

  var clean = findingsOf(r, 'clean');
  ok('every checked category reports clean', clean.length === 4);
  var labels = clean.map(function (f) { return f.facts.category; });
  ['container names', 'published ports', 'networks, volumes, configs and secrets', 'folders on the array'].forEach(function (label) {
    ok('clean list mentions "' + label + '"', labels.indexOf(label) >= 0);
  });
})();

/* =========================================================================
 * I. Three or more stacks — a clash between two INCOMING stacks
 * ========================================================================= */

console.log('\nI. Three-way merges catch a clash between two incoming stacks');

(function () {
  var host = { name: 'host', files: [], env: null, compose: { services: { app: { image: 'x', ports: [] } },
    volumes: {}, networks: {}, configs: {}, secrets: {}, stack_x_unraid: {} } };
  var a = { name: 'a', files: [], env: null, compose: { services: { shared: { image: 'x', ports: [] } },
    volumes: {}, networks: {}, configs: {}, secrets: {}, stack_x_unraid: {} } };
  var b = { name: 'b', files: [], env: null, compose: { services: { shared: { image: 'y', ports: [] } },
    volumes: {}, networks: {}, configs: {}, secrets: {}, stack_x_unraid: {} } };

  var r = M.examine(host, [a, b]);
  var clash = findingsOf(r, 'container-name-clash').filter(function (f) { return f.facts.field === 'service'; });
  ok('neither incoming service collides with the host, but they collide with each other', clash.length === 1);
  ok('the SECOND ticked stack is the one renamed, since the first already claimed the name',
     clash[0].stack === 'b' && clash[0].facts.to === 'shared_b');
})();

/* =========================================================================
 * J. buildMergedStructure — the decided shape
 * ========================================================================= */

console.log('\nJ. buildMergedStructure');

(function () {
  var pair = loadPair('container-name-clash');
  var structure = M.buildMergedStructure(pair.host, [pair.incoming], {}, { date: '2026-09-14' });

  ok('the host\'s own service is carried across unrenamed',
     structure.servicesOrder.some(function (s) { return s.origin === 'host' && s.name === 'web'; }));
  ok('the incoming service is appended under its clash-resolved name',
     structure.servicesOrder.some(function (s) { return s.origin === 'incoming' && s.name === 'web_incoming'; }));
  var incomingEntry = structure.servicesOrder.filter(function (s) { return s.origin === 'incoming'; })[0];
  ok('it carries a header comment saying where it came from and when',
     /folded in from incoming, 2026-09-14/.test(incomingEntry.headerComment));
  ok('the folded-in stack\'s stack-level x-unraid rides on its own service, not the host',
     incomingEntry.service.x_unraid !== undefined);
})();

/* =========================================================================
 * K. buildMergedText — the write splice (PLAN_148 phase 4)
 * ========================================================================= */

console.log('\nK. buildMergedText — the write splice');

(function () {
  var host = loadRaw('comments-and-anchor', 'host');
  var incoming = loadRaw('comments-and-anchor', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('no refusals for a clean pair', r.refusals.length === 0);
  ok('the comment above the anchor service survives, on its own line',
     /# the shared settings every container here inherits\n\s*base: &base/.test(r.composeText));
  ok('the anchor still comes before its alias after splicing',
     r.composeText.indexOf('&base') < r.composeText.indexOf('<<: *base'));
  ok('each arrival carries a "folded in from" note, indented to its own column, ahead of its own lead comment',
     /\n  # folded in from incoming, 2026-09-14\n  # the shared settings/.test(r.composeText));

  var reparsed = CM.parse(r.composeText);
  ok('the merged text itself still parses clean, no warnings', reparsed.warnings.length === 0);
  ok('...and is not left sealed as unparsable', reparsed.root.kind === 'map');

  assertMergedIsValid('comments-and-anchor', r.composeText, ['web', 'base', 'worker']);
})();

(function () {
  var host = loadRaw('identity', 'host');
  var incoming = loadRaw('identity', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the folded-in stack\'s icon lands on its own service\'s x-unraid, not the host\'s',
     /db:\n\s*image: mariadb:11\n\s*x-unraid:\n\s*icon: /.test(r.composeText));
  ok('...and its description alongside it', /description: "The demo application's own database"/.test(r.composeText));
  ok('the host\'s own service carries no x-unraid at all — nothing was pushed onto it',
     !/web:\n(?:.*\n)*?\s*x-unraid:/.test(r.composeText.split('db:')[0]));

  assertMergedIsValid('identity', r.composeText, ['web', 'db']);
})();

(function () {
  var host = loadRaw('storage-volume', 'host');
  var incoming = loadRaw('storage-volume', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the recommended "keep using what already exists" answer is written as an external volume',
     /dbdata:\n\s*external: true\n\s*name: incoming_dbdata/.test(r.composeText));

  // The defect this case exists to catch: the external stub used to be
  // written unindented, so "dbdata" landed as a stray top-level key instead
  // of a child of volumes: — invisible to a plain string match, but not to
  // a real parse.
  assertMergedIsValid('storage-volume (recommended)', r.composeText, ['web', 'db']);
})();

(function () {
  var host = loadRaw('env-clash', 'host');
  var incoming = loadRaw('env-clash', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the joined .env keeps the host\'s own comment untouched',
     /^# the host's own settings\nTZ=Europe\/London/.test(r.envText));
  ok('the renamed setting is never silently merged into a comment — it keeps its own value',
     /^DB_PASSWORD_INCOMING=dbsecret(\s|$)/m.test(r.envText));
  ok('...with the rename explained as an actual comment, marked with "#"',
     /DB_PASSWORD_INCOMING=dbsecret\s+# renamed:/.test(r.envText));
  ok('a value shared unchanged (TZ) is written once only', (r.envText.match(/^TZ=/mg) || []).length === 1);

  assertMergedIsValid('env-clash', r.composeText, ['web', 'db']);
})();

(function () {
  var host = loadRaw('container-name-clash', 'host');
  var incoming = loadRaw('container-name-clash', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the clashing service and its container_name both carry the resolved name',
     /web_incoming:\n\s*image: mariadb:11\n\s*container_name: web_incoming/.test(r.composeText));
  ok('the host\'s own untouched service is still exactly as it was',
     /web:\n\s*image: nginx:latest\n\s*container_name: web\n/.test(r.composeText));

  assertMergedIsValid('container-name-clash', r.composeText, ['web', 'web_incoming']);
})();

(function () {
  var host = loadRaw('shorthand-clash', 'host');
  var incoming = loadRaw('shorthand-clash', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the disagreeing network is renamed, and the arriving service follows the rename',
     /networks:\n\s*- demo_incoming/.test(r.composeText));
  ok('both networks end up declared, under their own names', /demo:\n\s*driver: bridge/.test(r.composeText) &&
     /demo_incoming:\n\s*driver: macvlan/.test(r.composeText));

  assertMergedIsValid('shorthand-clash', r.composeText, ['web', 'db']);
})();

(function () {
  var host = loadRaw('wiring', 'host');
  var incoming = loadRaw('wiring', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('the host\'s own address becomes the arriving service\'s name and real port',
     /DB_ADDRESS: db:3306/.test(r.composeText));
  ok('the address text that used to name this server is gone, not merely commented out',
     r.composeText.indexOf('192.0.2.88') === -1);

  assertMergedIsValid('wiring', r.composeText, ['web', 'db']);
})();

(function () {
  // A file-clash refusal (phase 2's own D fixture) still builds text — the
  // caller is the one that must refuse to WRITE it, so both halves of that
  // contract get their own proof: the finding is there, and it says refusal.
  var host = loadRaw('relative-path-clash', 'host');
  var incoming = loadRaw('relative-path-clash', 'incoming');
  var r = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });

  ok('a file-clash pair is reported back as a refusal', r.refusals.length === 1 &&
     r.refusals[0].kind === 'file-clash' && r.refusals[0].facts.name === 'config');

  // Refused or not, the text buildMergedText() hands back for the "what
  // would happen" preview must still be real YAML — nothing here writes it,
  // but the caller still renders it.
  assertMergedIsValid('relative-path-clash', r.composeText, ['web', 'db']);
})();

/* =========================================================================
 * L. buildMergedText honouring opts.decisions — the wizard's actual answer,
 * not always the recommended one. Each case below is proved against BOTH
 * the unanswered/recommended path (already covered above for most of these
 * kinds, repeated here where it matters) and the non-recommended one, so a
 * regression that made every decision a no-op would fail here even though
 * section K's own cases — all recommended-path — would still pass.
 * ========================================================================= */

console.log('\nL. buildMergedText honouring opts.decisions');

(function () {
  // storage-volume: "start with empty storage" instead of the recommended
  // "keep using what already exists".
  var host = loadRaw('storage-volume', 'host');
  var incoming = loadRaw('storage-volume', 'incoming');

  var recommended = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });
  ok('unanswered still keeps the recommended external stub (no regression)',
     /dbdata:\n\s*external: true\n\s*name: incoming_dbdata/.test(recommended.composeText));
  assertMergedIsValid('storage-volume L (recommended)', recommended.composeText, ['web', 'db']);

  var decided = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'storage-volume|incoming|0': 'start-empty' }
  });
  ok('"start with empty storage" writes an ordinary named volume, not the external stub',
     /^volumes:\n\s*dbdata: \{\}/m.test(decided.composeText) &&
     decided.composeText.indexOf('external: true') === -1);
  ok('choosing start-empty changes the text versus the recommended path',
     decided.composeText !== recommended.composeText);
  ok('start-empty is not itself a refusal', decided.refusals.length === 0);
  assertMergedIsValid('storage-volume L (start-empty)', decided.composeText, ['web', 'db']);

  var stopped = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'storage-volume|incoming|0': 'stop-here' }
  });
  ok('"stop here" on a storage-volume finding surfaces as a refusal',
     stopped.refusals.length === 1 && stopped.refusals[0].kind === 'storage-volume');
  assertMergedIsValid('storage-volume L (stop-here)', stopped.composeText, ['web', 'db']);
})();

(function () {
  // settings-join: "stop here" instead of the recommended "join them".
  var host = loadRaw('env-clash', 'host');
  var incoming = loadRaw('env-clash', 'incoming');

  var recommended = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });
  ok('unanswered settings-join still has no refusal (no regression)', recommended.refusals.length === 0);
  assertMergedIsValid('env-clash L (recommended)', recommended.composeText, ['web', 'db']);

  var stopped = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'settings-join|incoming|0': 'stop-here' }
  });
  ok('"stop here" on a settings-join finding surfaces as a refusal',
     stopped.refusals.length === 1 && stopped.refusals[0].kind === 'settings-join');
  assertMergedIsValid('env-clash L (stop-here)', stopped.composeText, ['web', 'db']);
})();

(function () {
  // port-clash: a wizard-supplied free port instead of the recommended (but
  // today unimplemented, hence unresolved) "free port", and "stop
  // publishing" instead.
  var host = loadRaw('port-clash', 'host');
  var incoming = loadRaw('port-clash', 'incoming');

  var recommended = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });
  ok('unanswered ("free port" with no number) leaves the clashing port exactly as written',
     /- "8091:3306"/.test(recommended.composeText));
  assertMergedIsValid('port-clash L (recommended)', recommended.composeText, ['web', 'db']);

  var freed = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'port-clash|incoming|0': 9091 }
  });
  ok('a chosen free port rewrites the incoming service\'s own published port, keeping the container side',
     /- "9091:3306"/.test(freed.composeText) && freed.composeText.indexOf('8091:3306') === -1);
  ok('the host\'s own port on the same number is untouched',
     /web:\n(?:.*\n)*?\s*- "8091:80"/.test(freed.composeText));
  assertMergedIsValid('port-clash L (free port)', freed.composeText, ['web', 'db']);

  var stopped = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'port-clash|incoming|0': 'stop-publishing' }
  });
  ok('"stop publishing" removes the incoming service\'s ports: entry entirely',
     stopped.composeText.indexOf('8091:3306') === -1);
  ok('...without touching the host\'s own port', /- "8091:80"/.test(stopped.composeText));
  assertMergedIsValid('port-clash L (stop-publishing)', stopped.composeText, ['web', 'db']);
})();

(function () {
  // wiring: address-rewire unticked leaves the address as written;
  // port-unneeded ticked removes the publication it offers unticked by
  // default.
  var host = loadRaw('wiring', 'host');
  var incoming = loadRaw('wiring', 'incoming');

  var recommended = MW.buildMergedText(host, [incoming], { date: '2026-09-14' });
  ok('unanswered address-rewire still rewires by default (no regression)',
     /DB_ADDRESS: db:3306/.test(recommended.composeText));
  ok('unanswered port-unneeded still leaves the port published by default (no regression)',
     /- "3307:3306"/.test(recommended.composeText));
  assertMergedIsValid('wiring L (recommended)', recommended.composeText, ['web', 'db']);

  var unticked = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'address-rewire|host|0': false }
  });
  ok('unticking the address-rewire leaves the address exactly as the author wrote it',
     /DB_ADDRESS: 192\.0\.2\.88:3307/.test(unticked.composeText) &&
     unticked.composeText.indexOf('DB_ADDRESS: db:3306') === -1);
  assertMergedIsValid('wiring L (unticked)', unticked.composeText, ['web', 'db']);

  var closed = MW.buildMergedText(host, [incoming], {
    date: '2026-09-14', decisions: { 'port-unneeded|incoming|1': true }
  });
  ok('ticking port-unneeded removes that publication',
     closed.composeText.indexOf('3307:3306') === -1);
  ok('...while still rewiring the address that made it unneeded',
     /DB_ADDRESS: db:3306/.test(closed.composeText));
  assertMergedIsValid('wiring L (closed)', closed.composeText, ['web', 'db']);
})();

console.log('\nM. A stack\'s name is its leaf, never its path');

// The wizard (stacks.js) identifies a stack by its full path under the
// store root ("DEV-TESTING/demo-db"), but hands buildMergedText() only the
// LEAF — the same string that is both the stack's directory name and the
// compose project name Docker actually uses. These cases stand in for that
// call site: they build the same {name, text, envText, files} shape a real
// wizard run would, with `name` already reduced to the leaf, and prove the
// merged output never leaks the folder segment into anything Docker (or a
// person) would read as an identity.
function leafOf(name) {
  var parts = String(name).split('/');
  return parts[parts.length - 1];
}

(function () {
  var hostRaw = loadRaw('storage-volume', 'host');
  var incomingRaw = loadRaw('storage-volume', 'incoming');
  // The incoming stack lives at "DEV-TESTING/demo-db" under the root; the
  // wizard passes buildMergedText() its leaf, "demo-db".
  var incoming = { name: leafOf('DEV-TESTING/demo-db'), text: incomingRaw.text, envText: incomingRaw.envText, files: incomingRaw.files };
  var r = MW.buildMergedText(hostRaw, [incoming], { date: '2026-09-14' });

  ok('the external volume is named after the stack\'s leaf ("demo-db_dbdata")',
     /dbdata:\n\s*external: true\n\s*name: demo-db_dbdata/.test(r.composeText));
  ok('...and never after its full path', r.composeText.indexOf('DEV-TESTING') === -1 &&
     r.composeText.indexOf('DEV-TESTING/demo-db_dbdata') === -1);

  assertMergedIsValid('leaf-name (storage-volume)', r.composeText, ['web', 'db']);
})();

(function () {
  var hostRaw = loadRaw('env-clash', 'host');
  var incomingRaw = loadRaw('env-clash', 'incoming');
  var incoming = { name: leafOf('DEV-TESTING/demo-db'), text: incomingRaw.text, envText: incomingRaw.envText, files: incomingRaw.files };
  var r = MW.buildMergedText(hostRaw, [incoming], { date: '2026-09-14' });

  ok('a settings clash is renamed with the leaf as its suffix ("DB_PASSWORD_DEMO_DB")',
     /^DB_PASSWORD_DEMO_DB=dbsecret(\s|$)/m.test(r.envText));
  ok('...never with the full path folded in ("DB_PASSWORD_DEV_TESTING_DEMO_DB")',
     r.envText.indexOf('DEV_TESTING') === -1);

  assertMergedIsValid('leaf-name (env-clash)', r.composeText, ['web', 'db']);
})();

/* =========================================================================
 * Summary
 * ========================================================================= */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
