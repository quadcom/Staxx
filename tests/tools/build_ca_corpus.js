/* StaXX — PLAN_179 part 3: builds the Community Applications merge corpus.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/tools/build_ca_corpus.js
 *
 * Run by hand, never by a suite. Downloads (or reuses a cached copy of) the public Community
 * Applications feed, converts a handful of real multi-container app families with StaXX's own
 * ca-convert.js — exactly the code path the Apps window's caOpenConverted() calls, so a stack
 * folder here is what a person installing that app from Community Applications would actually
 * get — then wires each family together the way a person does after the install finishes: an
 * address that names another member of the family by its container name, or by a generic
 * "the box's own address" placeholder (HOSTIPADDRESS, IP ADDRESSES, [IP]), is pointed at
 * 192.0.2.10 (a documentation-range address — RFC 5737 — never a real one) and that member's
 * own published host port.
 *
 * The output is committed to the repository — tests/merge_corpus.js runs against it offline,
 * so a feed change can never silently change what that suite is testing. Re-running this
 * generator is a deliberate act; its diff against what is already committed is read before it
 * replaces anything.
 *
 * Only four of the nine families PLAN_179 asked after turned out to have every part shipped as
 * a separate template by the same Community Applications author — the other five are recorded
 * in INDEX.md, with the reason, rather than forced.
 */

'use strict';

var fs = require('fs');
var path = require('path');
var https = require('https');

var CA = require('../../src/staxx/usr/local/emhttp/plugins/staxx/javascript/ca-convert.js');
var CM = require('../../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var CORPUS_DIR = path.join(__dirname, '..', 'fixtures', 'ca-corpus');
var FEED_DIR = path.join(CORPUS_DIR, '.feed');
var FEED_PATH = path.join(FEED_DIR, 'applicationFeed.json');
var FEED_URL = 'https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json';
var BOX_IP = '192.0.2.10';
var TODAY = '2026-09-24';

/* =========================================================================
 * Feed — fetched once, then cached under .feed/ (gitignored — see .gitignore)
 * ========================================================================= */

function fetchFeed() {
  return new Promise(function (resolve, reject) {
    https.get(FEED_URL, function (res) {
      if (res.statusCode !== 200) { reject(new Error('feed fetch failed: HTTP ' + res.statusCode)); return; }
      var chunks = [];
      res.on('data', function (c) { chunks.push(c); });
      res.on('end', function () { resolve(Buffer.concat(chunks).toString('utf8')); });
    }).on('error', reject);
  });
}

function loadFeed() {
  if (fs.existsSync(FEED_PATH)) {
    console.log('using cached feed: ' + FEED_PATH);
    return Promise.resolve(JSON.parse(fs.readFileSync(FEED_PATH, 'utf8')));
  }
  console.log('fetching feed from ' + FEED_URL + ' ...');
  return fetchFeed().then(function (text) {
    fs.mkdirSync(FEED_DIR, { recursive: true });
    fs.writeFileSync(FEED_PATH, text);
    console.log('cached feed at ' + FEED_PATH);
    return JSON.parse(text);
  });
}

function byName(applist, name) {
  var hit = applist.filter(function (a) { return a.Name === name; });
  if (hit.length !== 1) throw new Error('expected exactly one feed entry named "' + name + '", found ' + hit.length);
  return hit[0];
}

/* =========================================================================
 * Conversion — the same call caOpenConverted() makes (stacks.js), so a
 * generated fixture matches what a real install produces, icons and
 * x-unraid included. origin is left at its default ('community-applications')
 * since every app here comes from the feed, never a raw template XML.
 * ========================================================================= */

function convertApp(app) {
  var result = CA.convert(app, {
    importId: app.TemplateURL || app.Name,
    importName: app.Name
  });
  if (result.warnings.length) {
    console.log('  [' + app.Name + '] warnings:');
    result.warnings.forEach(function (w) { console.log('    - ' + w); });
  }
  return result;
}

// Finds the line "    KEY: <old>" (optionally quoted, with a trailing comment) inside one
// service's block and rewrites its value, keeping the indentation and any comment exactly as
// convert() wrote them. Refuses loudly rather than silently doing nothing, since a wiring step
// that stops matching after a feed change is exactly the kind of silent drift this corpus exists
// to catch.
function rewireEnv(yaml, key, oldVal, newVal, why) {
  var esc = function (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
  var re = new RegExp('^(\\s*' + esc(key) + ':\\s*)"?' + esc(oldVal) + '"?(\\s*(#.*)?)$', 'm');
  if (!re.test(yaml)) {
    throw new Error('rewireEnv: "' + key + '" with value "' + oldVal + '" not found — ' + why);
  }
  return yaml.replace(re, function (m, lead, tail) { return lead + '"' + newVal + '"' + tail; });
}

/* =========================================================================
 * Families
 * ========================================================================= */

var FOUND = [];   // { id, label, build(applist) -> { members: [{name, yaml}], wiring: [str] } }
var SKIPPED = [
  { label: 'Paperless-ngx + Redis + Postgres',
    reason: 'linuxserver\'s Repository ships paperless-ngx and mariadb, but no Redis template of ' +
      'its own; no single author in the feed ships all three parts (paperless-ngx, Redis, ' +
      'Postgres/MariaDB) as separate templates, so this family could not be built.' },
  { label: 'Authentik (server, worker, Postgres, Redis)',
    reason: 'zuerrex\'s Repository ships authentik-server, authentik-worker and authentik-ldap; ' +
      'IBRACORP\'s Repository ships authentik and authentik-worker. Neither author also ships a ' +
      'Postgres or Redis template, so the database and cache halves of this family do not exist ' +
      'as separate templates from the same author.' },
  { label: 'Frigate + Mosquitto',
    reason: 'no genuine Frigate template exists in this feed at all — the only match for "frigate" ' +
      'is Frigate-Plate-Recognizer (grtgbln\'s Repository), a companion tool, not Frigate itself — ' +
      'so there is no first part to pair a Mosquitto template with.' },
  { label: 'Gitea + database',
    reason: 'fanningert\'s Repository ships Gitea alone; Gitea runs on its own embedded SQLite ' +
      'database, so no separate database template accompanies it from the same author.' },
  { label: 'Mealie or Photoprism + database',
    reason: 'Mealie (Selfhosters Unraid Discord Repository) and PhotoPrism (Findthelorax\'s ' +
      'Repository) each ship alone — neither author also ships a MariaDB or Postgres template, ' +
      'and both apps can run against an embedded database, so neither template brings one along.' }
];

/* ---- Tube Archivist ---------------------------------------------------- */

FOUND.push({
  id: 'tube-archivist',
  label: 'Tube Archivist (TubeArchivist, TubeArchivist-ES, TubeArchivist-Redis — ' +
    'TubeArchivist\'s Official Repository)',
  build: function (applist) {
    var names = ['TubeArchivist', 'TubeArchivist-ES', 'TubeArchivist-Redis'];
    var apps = names.map(function (n) { return byName(applist, n); });
    var results = apps.map(convertApp);
    var yaml = {};
    names.forEach(function (n, i) { yaml[n] = results[i].yaml; });

    var wiring = [];

    // TA_HOST ships as the literal placeholder text "IP ADDRESSES" (the Apps window shows the
    // same words in the field) — it names the server itself, not a sibling, so it becomes the
    // box's own address with no port.
    yaml.TubeArchivist = rewireEnv(yaml.TubeArchivist, 'TA_HOST', 'IP ADDRESSES', BOX_IP,
      'TubeArchivist\'s own web address');
    wiring.push('`TA_HOST` shipped as the placeholder text "IP ADDRESSES" (the Apps window shows ' +
      'the same words) — set to the box\'s own address, ' + BOX_IP + '.');

    // REDIS_CON names the Redis container as "archivist-redis" — TubeArchivist-Redis's own
    // container name is literally "TubeArchivist-Redis" (Docker names must start with an
    // alphanumeric and this one does, so containerNameFor() in ca-convert.js kept it verbatim),
    // not "archivist-redis"; the name in the template is a stale/legacy hostname that only ever
    // resolved when TubeArchivist ran the classic docker-compose bundle this template does not
    // reproduce. It is still unmistakably a pointer at the Redis member, by name and by the
    // fixed port (6379) that member's own template publishes, so it is wired the same way an
    // address-rewire finding would fix it after a merge: host swapped for the box's address,
    // port left alone.
    yaml.TubeArchivist = rewireEnv(yaml.TubeArchivist, 'REDIS_CON', 'redis://archivist-redis:6379',
      'redis://' + BOX_IP + ':6379', 'points at the Redis member by its old bundle hostname');
    wiring.push('`REDIS_CON` named the Redis member by its old hostname ("archivist-redis"), not ' +
      'its real container name ("TubeArchivist-Redis") — set to ' + BOX_IP +
      ':6379, 6379 being TubeArchivist-Redis\'s own published host port.');

    // ES_URL: same shape, HOSTIPADDRESS is the box, and the port (9200) is Elasticsearch's own
    // published host port.
    yaml.TubeArchivist = rewireEnv(yaml.TubeArchivist, 'ES_URL', 'http://HOSTIPADDRESS:9200',
      'http://' + BOX_IP + ':9200', 'points at the Elasticsearch member by placeholder');
    wiring.push('`ES_URL` shipped as the placeholder "http://HOSTIPADDRESS:9200" — set to ' +
      'http://' + BOX_IP + ':9200, 9200 being TubeArchivist-ES\'s own published host port.');

    return { members: names.map(function (n) { return { name: n, yaml: yaml[n] }; }), wiring: wiring };
  }
});

/* ---- Immich ------------------------------------------------------------ */

FOUND.push({
  id: 'immich',
  label: 'Immich (immich-server, immich-machine-learning, immich-postgres, immich-redis — ' +
    'sgraaf\'s Repository)',
  build: function (applist) {
    var names = ['immich-server', 'immich-machine-learning', 'immich-postgres', 'immich-redis'];
    var apps = names.map(function (n) { return byName(applist, n); });
    var results = apps.map(convertApp);
    var yaml = {};
    names.forEach(function (n, i) { yaml[n] = results[i].yaml; });

    // Every member ships on the same named network ("immich", written external: true by
    // ca-convert.js's own networkInfo()) and DB_HOSTNAME/REDIS_HOSTNAME/
    // IMMICH_MACHINE_LEARNING_URL already name the other members by their real container
    // names (immich-postgres, immich-redis, immich-machine-learning — each equal to that
    // template's own Name, which containerNameFor() keeps verbatim). Docker's own DNS on a
    // named network resolves a container name directly, so nothing here points at a
    // placeholder or the box's address — there is nothing for this generator to rewire.
    var wiring = ['No rewiring was needed. All four templates already address each other by ' +
      'real container name (immich-postgres, immich-redis, immich-machine-learning) over the ' +
      'shared external network "immich" that every one of them declares — that is genuine ' +
      'Docker DNS between containers on the same network, not a placeholder.',
      'DB_PASSWORD (immich-server) and POSTGRES_PASSWORD (immich-postgres) both ship blank in ' +
      'the feed, with no Default to fall back to either — the Apps window would show both ' +
      'fields empty too, so nothing was filled in; a real install has to type the same ' +
      'password into both.'];

    return { members: names.map(function (n) { return { name: n, yaml: yaml[n] }; }), wiring: wiring };
  }
});

/* ---- Nextcloud + MariaDB ------------------------------------------------ */

FOUND.push({
  id: 'nextcloud-mariadb',
  label: 'Nextcloud + MariaDB (nextcloud, mariadb — linuxserver\'s Repository)',
  build: function (applist) {
    var names = ['nextcloud', 'mariadb'];
    var apps = names.map(function (n) { return byName(applist, n); });
    var results = apps.map(convertApp);
    var yaml = {};
    var notes = [];
    names.forEach(function (n, i) { yaml[n] = results[i].yaml; notes = notes.concat(results[i].notes.map(function (t) { return n + ': ' + t; })); });

    var wiring = ['Neither template\'s environment names the other: linuxserver\'s Nextcloud ' +
      'image configures its database through its own first-run web wizard, not an env var, so ' +
      'there is nothing here for a generator to point at the other container — a real install ' +
      'types MariaDB\'s container name and the MYSQL_* values into that wizard by hand once both ' +
      'containers are running.'];
    if (notes.length) {
      wiring.push('Both templates ship several Path/Variable settings blank; ca-convert.js\'s ' +
        'own default-filling (the same fallback the Apps window\'s fields use) supplied:');
      notes.forEach(function (n) { wiring.push('  - ' + n); });
    }

    return { members: names.map(function (n) { return { name: n, yaml: yaml[n] }; }), wiring: wiring };
  }
});

/* ---- A VPN'd downloader ------------------------------------------------- */

FOUND.push({
  id: 'vpn-downloader',
  label: 'A VPN\'d downloader (binhex-official-gluetun, binhex-qbittorrent — Binhex\'s Repository)',
  build: function (applist) {
    var names = ['binhex-official-gluetun', 'binhex-qbittorrent'];
    var apps = names.map(function (n) { return byName(applist, n); });
    var results = apps.map(convertApp);
    var yaml = {};
    names.forEach(function (n, i) { yaml[n] = results[i].yaml; });

    var wiring = [];

    // Neither template ships network_mode: container:<other> on its own — a VPN'd downloader is
    // set up by hand, in the Unraid UI, after both containers already exist: qBittorrent's
    // network type is switched from bridge to "Container: binhex-official-gluetun" so every
    // packet it sends leaves through the VPN container's tunnel instead of its own interface.
    // That is done here the same way compose-model.js's own setNetworkMode() does it for the
    // form (PLAN_64 phase C) — swap networks: for network_mode: "container:<name>" — except a
    // plain text rewrite is enough for a fixture nobody edits by hand afterwards, where
    // setNetworkMode()'s own job (stashing the original networks: block so a later "share no
    // container's network" click can restore it) buys nothing.
    var beforeNet = '    networks:\n      - default\n';
    if (yaml['binhex-qbittorrent'].indexOf(beforeNet) === -1) {
      throw new Error('vpn-downloader: expected qBittorrent\'s default networks: block was not found');
    }
    yaml['binhex-qbittorrent'] = yaml['binhex-qbittorrent'].replace(beforeNet,
      '    network_mode: "container:binhex-official-gluetun"\n');
    wiring.push('`binhex-qbittorrent`\'s network was switched from the default bridge network to ' +
      '`network_mode: "container:binhex-official-gluetun"` — sharing the VPN container\'s network ' +
      'stack entirely is the whole point of this pairing, and Community Applications templates ' +
      'never ship that wiring themselves since the VPN container\'s name is only known once it ' +
      'has actually been installed.');

    // Compose refuses a service that publishes ports: while also sharing another container's
    // network (there is no interface of its own left to publish them on) — every packet in and
    // out already goes through binhex-official-gluetun's own published ports instead. The live
    // ports: block is turned into the same commented "# ports:" note compose-model.js's own
    // portsNote()/setPortsNote() write when a network mode forbids one (see PLAN_104) — nothing
    // written by the template is lost, only commented, and it reads back exactly the way that
    // pair of functions expects.
    var portsMatch = /^( {4}ports:\n(?: {6}-.*\n)+)/m.exec(yaml['binhex-qbittorrent']);
    if (!portsMatch) throw new Error('vpn-downloader: expected qBittorrent\'s ports: block was not found');
    var portLines = portsMatch[1].split('\n').filter(function (l) { return l.trim() !== ''; });
    var commented = ['    # ports:'].concat(portLines.slice(1).map(function (l) {
      return '    #   ' + l.replace(/^ {6}/, '');
    })).join('\n') + '\n';
    yaml['binhex-qbittorrent'] = yaml['binhex-qbittorrent'].replace(portsMatch[1], commented);
    wiring.push('`binhex-qbittorrent`\'s ports: block was turned into a commented `# ports:` note ' +
      '(the same shape the form writes for any service on a network mode that forbids one) — ' +
      'Compose refuses a live ports: entry alongside network_mode: "container:…", since the ' +
      'service has no interface of its own left to publish on.');

    return { members: names.map(function (n) { return { name: n, yaml: yaml[n] }; }), wiring: wiring };
  }
});

/* =========================================================================
 * Writing
 * ========================================================================= */

function writeLF(filePath, text) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, text.replace(/\r\n/g, '\n'));
}

function writeFamily(family, built) {
  var famDir = path.join(CORPUS_DIR, family.id);
  built.members.forEach(function (m) {
    var doc = CM.parse(m.yaml);
    if (doc.unreadTail) throw new Error(family.id + '/' + m.name + ': converted file does not fully parse');
    writeLF(path.join(famDir, m.name, 'compose.yaml'), m.yaml);
  });

  var lines = ['# ' + family.label, ''];
  lines.push('Members: ' + built.members.map(function (m) { return m.name; }).join(', ') + '.');
  lines.push('Every published host port and address below is exactly what the converted file ' +
    'holds — see each member\'s own compose.yaml.');
  lines.push('');
  lines.push('## Wiring');
  lines.push('');
  built.wiring.forEach(function (w) { lines.push(w); lines.push(''); });

  writeLF(path.join(famDir, 'WIRING.md'), lines.join('\n').replace(/\n{3,}/g, '\n\n'));
  console.log('wrote ' + family.id + ' (' + built.members.length + ' member' +
    (built.members.length === 1 ? '' : 's') + ')');
}

function writeIndex(builtFamilies) {
  var lines = ['# The Community Applications merge corpus', ''];
  lines.push('Generated by `tests/tools/build_ca_corpus.js` from the public Community Applications ' +
    'feed, converted through `ca-convert.js` exactly as the Apps window does. Committed, not ' +
    'regenerated by any suite — see that script\'s own header for why.');
  lines.push('');
  lines.push('## Families found (' + builtFamilies.length + ')');
  lines.push('');
  builtFamilies.forEach(function (f) {
    lines.push('- **' + f.family.id + '**  ' + f.family.label);
    lines.push('  ' + f.built.members.length + ' member(s): ' +
      f.built.members.map(function (m) { return m.name; }).join(', '));
  });
  lines.push('');
  lines.push('## Families skipped (' + SKIPPED.length + ')');
  lines.push('');
  SKIPPED.forEach(function (s) {
    lines.push('- **' + s.label + '** — ' + s.reason);
  });
  lines.push('');
  writeLF(path.join(CORPUS_DIR, 'INDEX.md'), lines.join('\n'));
}

/* =========================================================================
 * Run
 * ========================================================================= */

loadFeed().then(function (feed) {
  var applist = feed.applist;
  var builtFamilies = [];
  FOUND.forEach(function (family) {
    console.log('\n=== ' + family.id + ' ===');
    var built = family.build(applist);
    writeFamily(family, built);
    builtFamilies.push({ family: family, built: built });
  });
  writeIndex(builtFamilies);

  console.log('\nFound ' + builtFamilies.length + ' families, skipped ' + SKIPPED.length + '.');
  console.log('Wrote ' + CORPUS_DIR);
}).catch(function (err) {
  console.error('build_ca_corpus.js failed: ' + (err && err.message ? err.message : err));
  process.exit(1);
});
