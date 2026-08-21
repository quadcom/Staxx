/* StaXX — tests for the image-import builder (PLAN_31 step 3).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/image_import.js
 *
 * No framework, no npm, no network — same shape as ca_convert.js: one line
 * per case, non-zero exit on failure.
 *
 * The two README blocks below are quoted verbatim from a live measurement
 * of linuxserver/jellyfin's and postgres's own Docker Hub pages, wrapped in
 * a realistic README with other fenced blocks around them so the
 * block-finding is genuinely exercised, not just the parsing of an isolated
 * snippet.
 */

'use strict';

var IM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/image-import.js');
var CM = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

var producedYaml = [];   // every case's output, for the round-trip guard at the end

function build(image, source, facts, opts) {
  var r = IM.build(image, source, facts, opts);
  producedYaml.push(r.yaml);
  return r;
}

/* =========================================================================
 * Fixtures
 * ========================================================================= */

var JELLYFIN_README = [
  '# linuxserver/jellyfin',
  '',
  'Jellyfin is a media server.',
  '',
  '## Usage',
  '',
  '```bash',
  'docker pull lscr.io/linuxserver/jellyfin',
  '```',
  '',
  'Or with compose:',
  '',
  '```yaml',
  '---',
  'services:',
  '  jellyfin:',
  '    image: lscr.io/linuxserver/jellyfin:latest',
  '    container_name: jellyfin',
  '    environment:',
  '      - PUID=1000',
  '      - PGID=1000',
  '      - TZ=Etc/UTC',
  '      - JELLYFIN_PublishedServerUrl=http://192.168.0.5 #optional',
  '    volumes:',
  '      - /path/to/jellyfin/library:/config',
  '      - /path/to/tvseries:/data/tvshows',
  '      - /path/to/movies:/data/movies',
  '    ports:',
  '      - 8096:8096',
  '      - 8920:8920 #optional',
  '      - 7359:7359/udp #optional',
  '      - 1900:1900/udp #optional',
  '    restart: unless-stopped',
  '```',
  '',
  'Check logs with:',
  '',
  '```console',
  '$ docker logs jellyfin',
  '```',
  '',
  '## Support',
  '',
  'See the forums.'
].join('\n');

var POSTGRES_README = [
  '# postgres',
  '',
  '## How to use this image',
  '',
  '```',
  'docker pull postgres',
  '```',
  '',
  '```yaml',
  '# Use postgres/example user/password credentials',
  '',
  'services:',
  '',
  '  db:',
  '    image: postgres',
  '    restart: always',
  '    # set shared memory limit when using docker compose',
  '    shm_size: 128mb',
  '    # or set shared memory limit when deploy via swarm stack',
  '    #volumes:',
  '    #  - type: tmpfs',
  '    #    target: /dev/shm',
  '    #    tmpfs:',
  '    #      size: 134217728 # 128*2^20 bytes = 128Mb',
  '    environment:',
  '      POSTGRES_PASSWORD: example',
  '',
  '  adminer:',
  '    image: adminer',
  '    restart: always',
  '    ports:',
  '      - 8080:8080',
  '```',
  '',
  '```bash',
  'docker exec -it some-postgres psql -U postgres',
  '```'
].join('\n');

var NO_BLOCK_README = [
  '# some-image',
  '',
  '```bash',
  'docker run some-image',
  '```',
  '',
  '```console',
  'output goes here',
  '```'
].join('\n');

var JELLYFIN_CONFIG_LABELS = {
  'org.opencontainers.image.description': 'Jellyfin is a Free Software Media System.',
  'org.opencontainers.image.authors': 'linuxserver.io',
  'org.opencontainers.image.created': '2026-08-01T00:00:00Z',
  maintainer: 'linuxserver.io <info@linuxserver.io>'    // must be ignored — not an OCI key we read
};

var OPTS = { appdata: '/mnt/user/appdata/', timezone: 'America/Toronto' };
var OPTS_NO_TZ_NO_APPDATA = { appdata: '', timezone: '' };

/* =========================================================================
 * A. Route 1 — the README's own example
 * ========================================================================= */

console.log('\nA. README route — matching and corrections');

var jf = build('linuxserver/jellyfin', 'hub', { readme: JELLYFIN_README, labels: JELLYFIN_CONFIG_LABELS }, OPTS);
ok('lscr.io/linuxserver/jellyfin:latest in the block matches an import of linuxserver/jellyfin',
   jf.route === 'readme');
ok('PUID becomes 99 with a note', jf.yaml.indexOf('PUID=99') >= 0 &&
   jf.notes.some(function (n) { return /PUID to 99/.test(n); }));
ok('PGID becomes 100 with a note', jf.yaml.indexOf('PGID=100') >= 0 &&
   jf.notes.some(function (n) { return /PGID to 100/.test(n); }));
ok('TZ is set to the given zone with a note', jf.yaml.indexOf('TZ=America/Toronto') >= 0 &&
   jf.notes.some(function (n) { return /America\/Toronto/.test(n); }));
ok('all four /path/to/ paths move under appdata',
   jf.yaml.split('services:')[1].indexOf('/path/to/') === -1 &&
   jf.yaml.indexOf('/mnt/user/appdata/jellyfin/library:/config') >= 0 &&
   jf.yaml.indexOf('/mnt/user/appdata/tvseries:/data/tvshows') >= 0 &&
   jf.yaml.indexOf('/mnt/user/appdata/movies:/data/movies') >= 0);
ok('the path note lists all four original paths and mentions shares',
   jf.notes.some(function (n) {
     return /\/path\/to\/jellyfin\/library/.test(n) && /\/path\/to\/tvseries/.test(n) &&
       /\/path\/to\/movies/.test(n) && /share/.test(n);
   }));
ok('the #optional comment survives verbatim', jf.yaml.indexOf('#optional') >= 0);
ok('container_name survives verbatim', jf.yaml.indexOf('container_name: jellyfin') >= 0);
ok('nothing from the image\'s environment ever appears (no Env fields invented)',
   jf.yaml.indexOf('S6_VERBOSITY') === -1 && jf.yaml.indexOf('PG_MAJOR') === -1);
ok('x-unraid carries the description and author from the labels',
   jf.yaml.indexOf('Jellyfin is a Free Software Media System.') >= 0 &&
   jf.yaml.indexOf('author: linuxserver.io') >= 0);
ok('x-unraid has no icon key', !/^\s*icon:/m.test(jf.yaml.split('services:')[0]));

var jfNoTz = build('linuxserver/jellyfin', 'hub', { readme: JELLYFIN_README }, OPTS_NO_TZ_NO_APPDATA);
ok('TZ is left alone when opts.timezone is empty', jfNoTz.yaml.indexOf('TZ=Etc/UTC') >= 0 &&
   !jfNoTz.notes.some(function (n) { return /timezone/.test(n); }));
ok('paths are left alone when opts.appdata is empty', jfNoTz.yaml.indexOf('/path/to/jellyfin/library') >= 0);

var pg = build('postgres', 'hub', { readme: POSTGRES_README }, OPTS);
ok('a bare "image: postgres" matches an import of postgres', pg.route === 'readme');
var pgLib = build('library/postgres', 'hub', { readme: POSTGRES_README }, OPTS);
ok('a bare "image: postgres" also matches an import of library/postgres', pgLib.route === 'readme');
ok('postgres\'s import keeps both db and adminer',
   pg.yaml.indexOf('db:') >= 0 && pg.yaml.indexOf('adminer:') >= 0 && pg.yaml.indexOf('image: adminer') >= 0);
ok('...and says so in a note', pg.notes.some(function (n) { return /db/.test(n) && /adminer/.test(n); }));
ok('POSTGRES_PASSWORD still says example in the output', pg.yaml.indexOf('POSTGRES_PASSWORD: example') >= 0);
ok('...and there is a warning naming it',
   pg.warnings.some(function (w) { return /POSTGRES_PASSWORD/.test(w); }));
ok('commented-out alternatives survive verbatim', pg.yaml.indexOf('#  - type: tmpfs') >= 0);
ok('shm_size: survives verbatim', pg.yaml.indexOf('shm_size: 128mb') >= 0);

var unrelated = build('postgres', 'hub', {
  readme: ['```yaml', 'services:', '  web:', '    image: nginx:alpine', '```'].join('\n')
}, OPTS);
ok('a block whose only service uses an unrelated image is rejected, falling back',
   unrelated.route !== 'readme');

var noBlock = build('some/image', 'hub', { readme: NO_BLOCK_README }, OPTS);
ok('a README with no yaml block at all falls back', noBlock.route !== 'readme');
ok('...and wantConfig comes back true when no ports/volumes were supplied', noBlock.wantConfig === true);

/* =========================================================================
 * B. PUID/PGID exact-value rule
 * ========================================================================= */

console.log('\nB. PUID/PGID — only the exact documented default is touched');

function puidReadme(value) {
  return ['```yaml', 'services:', '  test:', '    image: postgres', '    environment:',
    '      - PUID=' + value, '```'].join('\n');
}

var already99 = build('postgres', 'hub', { readme: puidReadme('99') }, OPTS);
ok('a block already saying PUID=99 produces no note', !already99.notes.some(function (n) { return /PUID/.test(n); }));

var custom1001 = build('postgres', 'hub', { readme: puidReadme('1001') }, OPTS);
ok('PUID=1001 is left alone', custom1001.yaml.indexOf('PUID=1001') >= 0);
ok('...and produces no note', !custom1001.notes.some(function (n) { return /PUID/.test(n); }));

/* =========================================================================
 * C. The guard parse — a correction that would break the file is discarded
 * ========================================================================= */

console.log('\nC. Guard parse — a corrupting correction is thrown away');

// A TZ value quoted with a literal double quote embedded, crafted so the
// correction (blindly re-wrapping the new value in the same quote style)
// produces a value containing an unescaped ", which the parser locks.
var guardReadme = ['```yaml', 'services:', '  test:', '    image: postgres', '    environment:',
  '      TZ: "Etc/UTC"', '```'].join('\n');
var guarded = build('postgres', 'hub', { readme: guardReadme }, { appdata: '', timezone: 'Bad"Zone' });
ok('the guard restores the original line when a correction would corrupt the file',
   guarded.yaml.indexOf('TZ: "Etc/UTC"') >= 0 && guarded.yaml.indexOf('Bad"Zone') === -1);
ok('...and explains why, in a note',
   guarded.notes.some(function (n) { return /left exactly as the documentation wrote it/.test(n); }));
var guardDoc = CM.parse(guarded.yaml);
ok('the restored file itself is clean', guardDoc.sealed.length === 0);

/* =========================================================================
 * D. Route 2 — the image's own config
 * ========================================================================= */

console.log('\nD. Config route — ports and volumes from the image itself');

var cfg = build('linuxserver/jellyfin', 'hub', {
  ports: ['8096/tcp', '8920/tcp'], volumes: ['/config'], labels: JELLYFIN_CONFIG_LABELS
}, OPTS);
ok('facts with ports/volumes but no matching README block takes the config route', cfg.route === 'config');
ok('8096/tcp becomes "8096:8096" (default proto dropped)', cfg.yaml.indexOf('"8096:8096"') >= 0);
ok('8920/tcp likewise', cfg.yaml.indexOf('"8920:8920"') >= 0);
ok('/config becomes <appdata><stackname>/config:/config',
   cfg.yaml.indexOf('/mnt/user/appdata/jellyfin/config:/config') >= 0);
ok('the maintainer label is ignored', cfg.yaml.indexOf('linuxserver.io <info') === -1);
ok('nothing from the environment appears', cfg.yaml.indexOf('environment:') === -1);
ok('a note says these are a starting point', cfg.notes.some(function (n) { return /starting point/.test(n); }));

var cfgUdp = build('example/thing', 'hub', { ports: ['5353/udp'] }, OPTS);
ok('a udp port keeps its suffix', cfgUdp.yaml.indexOf('"5353:5353/udp"') >= 0);

var cfgLocal = build('example/thing', 'local', { ports: ['80/tcp'] }, OPTS);
ok('the local-source header names the server, not Docker Hub',
   /already on this server/.test(cfgLocal.yaml.split('\n')[0]));

/* =========================================================================
 * E. Route 3 — neither
 * ========================================================================= */

console.log('\nE. Bare route — the four-line skeleton');

var bareHub = build('some/newimage', 'hub', {}, OPTS);
ok('no facts at all falls back to the bare skeleton', bareHub.route === 'bare');
ok('wantConfig is true (nothing was ever supplied)', bareHub.wantConfig === true);
ok('the skeleton matches caSkeleton() exactly',
   bareHub.yaml === [
     'services:', '',
     '  # Added from a Docker Hub search — just the image, nothing else.',
     '  # Ports, paths and variables are not set; add whatever this container needs.',
     '  newimage:',
     '    image: some/newimage',
     '    restart: unless-stopped',
     ''
   ].join('\n'));

var bareLocal = build('some/newimage', 'local', {}, OPTS);
ok('the local bare skeleton uses the local wording',
   bareLocal.yaml.indexOf('already on this server') >= 0);

var off = build('some/newimage', 'hub', { off: true }, OPTS);
ok('facts.off short-circuits straight to the bare route', off.route === 'bare');
ok('...with wantConfig false (the switch means do not ask again)', off.wantConfig === false);

/* =========================================================================
 * G. Shapes a README example really uses, and links that must not be invented
 * ========================================================================= */

console.log('\nG. Sealed values, asked-and-empty, and a registry with no Hub page');

// A flow sequence, an anchor and a block scalar all seal a value in
// compose-model.js, and all three are ordinary in a published example. The
// block must still be accepted — the form shows a sealed value as one locked
// row, and rejecting the block would drop a good file for a much thinner one.
var FLOW_README = ['```yaml',
  'services:',
  '  gpu-thing:',
  '    image: someone/gpu-thing',
  '    command: ["sleep", "infinity"]',
  '    deploy:',
  '      resources:',
  '        reservations:',
  '          devices:',
  '            - capabilities: [gpu]',
  '```'].join('\n');

var flow = build('someone/gpu-thing', 'hub', { readme: FLOW_README }, { appdata: '/mnt/user/appdata/', timezone: 'America/Toronto' });
ok('a block holding a flow sequence is still accepted', flow.route === 'readme', flow.route);
ok('...and its flow lines survive verbatim', flow.yaml.indexOf('- capabilities: [gpu]') >= 0);
ok('...and it says nothing about a correction having been discarded',
   !flow.notes.some(function (n) { return /left exactly as the documentation wrote it/.test(n); }));

// The corrections still work in a block that already seals something — the
// guard compares against what was sealed before, not against nothing.
var FLOW_PUID = FLOW_README.replace('    command:', '    environment:\n      - PUID=1000\n      - TZ=Etc/UTC\n    command:');
var flowFixed = build('someone/gpu-thing', 'hub', { readme: FLOW_PUID }, { appdata: '/mnt/user/appdata/', timezone: 'America/Toronto' });
ok('a correction still applies alongside a sealed value', flowFixed.yaml.indexOf('- PUID=99') >= 0, flowFixed.yaml);
ok('...and the timezone with it', flowFixed.yaml.indexOf('- TZ=America/Toronto') >= 0);
ok('...and the flow line is untouched', flowFixed.yaml.indexOf('- capabilities: [gpu]') >= 0);

// Asked and told nothing is not the same as never asked: re-asking would pay
// for four registry requests to hear the same empty answer twice.
var emptyConfig = build('someone/nothing', 'hub', { readme: '', ports: [], volumes: [] }, {});
ok('an empty ports/volumes answer falls to the bare route', emptyConfig.route === 'bare');
ok('...and does not ask for the config again', emptyConfig.wantConfig === false);

// A private registry has no Docker Hub page, and pointing at one would write a
// dead link into the file.
var priv = build('registry.example.com:5000/team/thing:1.2', 'local', { ports: ['9000/tcp'] }, { appdata: '/mnt/user/appdata/' });
ok('a private-registry image invents no readme link', priv.yaml.indexOf('hub.docker.com') === -1, priv.yaml);
var mirrored = build('lscr.io/linuxserver/jellyfin:latest', 'hub', { ports: ['8096/tcp'] }, { appdata: '/mnt/user/appdata/' });
ok('a linuxserver mirror still gets its real Hub page',
   mirrored.yaml.indexOf('readme: https://hub.docker.com/r/linuxserver/jellyfin') >= 0, mirrored.yaml);

// Hub serves an official image only at /_/name; /r/library/name answers
// nothing, and a search result or a README example writes the long form often
// enough that this was a live dead link before it was fixed.
var official = build('library/nginx', 'hub', { ports: ['80/tcp'] }, { appdata: '/mnt/user/appdata/' });
ok('an official image written the long way gets the /_/ page, not /r/library/',
   official.yaml.indexOf('readme: https://hub.docker.com/_/nginx') >= 0, official.yaml);
var officialShort = build('nginx', 'hub', { ports: ['80/tcp'] }, { appdata: '/mnt/user/appdata/' });
ok('...and the short form lands on the same page',
   officialShort.yaml.indexOf('readme: https://hub.docker.com/_/nginx') >= 0, officialShort.yaml);

/* =========================================================================
 * I. Placeholder secrets the exact-value list let through
 *
 * Found against the live wordpress and duplicati documentation, both of which
 * slipped past an earlier version of this check.
 * ========================================================================= */

console.log('\nI. Placeholder and empty secrets');

// wordpress writes examplepass, not example — an exact-value list missed it,
// which is the one thing this check exists to prevent.
var WP_README = ['```yaml', 'services:', '  wordpress:', '    image: wordpress',
  '    environment:', '      WORDPRESS_DB_PASSWORD: examplepass',
  '      WORDPRESS_DB_USER: exampleuser', '  db:', '    image: mysql:8.0',
  '    environment:', '      MYSQL_PASSWORD: examplepass', '```'].join('\n');
var wp = build('wordpress', 'hub', { readme: WP_README }, OPTS);
ok('a value of examplepass is caught, not just a bare example',
   wp.warnings.some(function (w) { return /WORDPRESS_DB_PASSWORD/.test(w) && /placeholder/.test(w); }),
   JSON.stringify(wp.warnings));
ok('both services\' placeholder passwords are named',
   wp.warnings.some(function (w) { return /MYSQL_PASSWORD/.test(w); }));
ok('...and neither value is rewritten', wp.yaml.split('examplepass').length - 1 === 2);
ok('a user name that is equally a placeholder is not warned about — only secrets are',
   !wp.warnings.some(function (w) { return /WORDPRESS_DB_USER/.test(w); }));

// duplicati leaves one secret empty and unmarked, and two empty but #optional.
// The unmarked one is what stops the container working, so it is the one said.
var DUP_README = ['```yaml', 'services:', '  duplicati:',
  '    image: lscr.io/linuxserver/duplicati:latest', '    environment:',
  '      - SETTINGS_ENCRYPTION_KEY=',
  '      - DUPLICATI__WEBSERVICE_PASSWORD= #optional',
  '      - CLI_ARGS= #optional', '```'].join('\n');
var dup = build('linuxserver/duplicati', 'hub', { readme: DUP_README }, OPTS);
ok('an empty secret the documentation does not mark optional is warned about',
   dup.warnings.some(function (w) { return /SETTINGS_ENCRYPTION_KEY/.test(w) && /no value/.test(w); }),
   JSON.stringify(dup.warnings));
ok('an empty secret marked #optional is left in peace',
   !dup.warnings.some(function (w) { return /WEBSERVICE_PASSWORD/.test(w); }));
ok('exactly one warning, so the optional ones are not noise', dup.warnings.length === 1,
   JSON.stringify(dup.warnings));

/* =========================================================================
 * J. ENV_MAP_RE alignment, container-side /path/to/, and a false secrets:
 * ========================================================================= */

console.log('\nJ. Aligned env values, the container side of a mount, and secrets:');

// An aligned block ("PUID:   1000") used to miss ENV_MAP_RE's ":\s?" (at
// most one space) entirely — no correction, no note, and the container
// silently keeps running as uid 1000.
var ALIGNED_README = ['```yaml', 'services:', '  aligned:',
  '    image: someone/aligned-map-test', '    environment:',
  '      PUID:   1000', '      PGID:   1000',
  '    secrets:', '    volumes:',
  '      - /path/to/data:/path/to/data', '```'].join('\n');
var aligned = build('someone/aligned-map-test', 'hub', { readme: ALIGNED_README },
  { appdata: '/mnt/user/appdata', timezone: 'America/Toronto' });
ok('an aligned "PUID:   1000" is still corrected to 99',
   aligned.yaml.indexOf('PUID:   99') >= 0 || aligned.yaml.indexOf('PUID: 99') >= 0,
   aligned.yaml);
ok('...with the usual note',
   aligned.notes.some(function (n) { return /PUID to 99/.test(n); }));
ok('opts.appdata with no trailing slash still lands on a real path, not "appdatadata"',
   aligned.yaml.indexOf('/mnt/user/appdata/data:') >= 0 &&
   aligned.yaml.indexOf('appdatadata') === -1,
   aligned.yaml);
ok('only the host side of "/path/to/data:/path/to/data" is rewritten — the container side stays',
   aligned.yaml.indexOf('/mnt/user/appdata/data:/path/to/data') >= 0,
   aligned.yaml);
ok('a bare "secrets:" line is not mistaken for an empty SECRET_KEY_RE env var',
   !aligned.warnings.some(function (w) { return /secrets/.test(w); }),
   JSON.stringify(aligned.warnings));

/* =========================================================================
 * H. Round-trip guard — every produced file, every case
 * ========================================================================= */

console.log('\nH. Every produced file parses clean and round-trips byte for byte');

var roundtripFail = 0, formFail = 0, badSealFail = 0, thrown = 0;
producedYaml.forEach(function (yaml) {
  try {
    var doc = CM.parse(yaml);
    if (CM.serialise(doc) !== yaml) roundtripFail++;
    var form = CM.buildForm(doc);
    if (!form.ok) formFail++;
    // Root only. A sealed *value* is expected and fine — the overview is
    // written as a "|" block scalar, and a documentation example may bring a
    // flow sequence or an anchor with it, each of which shows as one locked
    // row and still round-trips. A sealed *root* is the real failure: the
    // whole file uneditable, which is what section G's shapes used to cause.
    if (!doc.root || doc.root.kind !== 'map') badSealFail++;
  } catch (e) {
    thrown++;
  }
});
ok('every one of ' + producedYaml.length + ' produced files round-trips byte for byte', roundtripFail === 0, roundtripFail + ' failed');
ok('every one of ' + producedYaml.length + ' produced files builds a readable form', formFail === 0, formFail + ' failed');
ok('not one of ' + producedYaml.length + ' produced files seals as a whole', badSealFail === 0, badSealFail + ' failed');
ok('build() never throws on any of ' + producedYaml.length + ' cases', thrown === 0, thrown + ' threw');

/* ---- summary ------------------------------------------------------------ */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
