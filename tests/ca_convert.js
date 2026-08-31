/* StaXX — tests for the Community Applications template converter.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/ca_convert.js
 *
 * No framework, no npm, no network — same shape as yaml_roundtrip.js: one
 * line per case, non-zero exit on failure.
 *
 * Fixtures below are real entries from the live Community Applications feed
 * (applicationFeed.json), trimmed to the fields ca-convert.js actually reads
 * — the noisy popularity/trend fields every entry also carries add nothing
 * to what is being tested. Two fixtures (a Device with no value, and a
 * Description long enough to truncate) are not present verbatim in the feed
 * and are built by hand, because those specific edge cases were not found in
 * a quick search of it; every other fixture is copied field-for-field.
 */

'use strict';

var fs = require('fs');
var path = require('path');

var CA = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/ca-convert.js');
var Y  = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var pass = 0, fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ok    ' + name); return true; }
  fail++;
  console.log('  FAIL  ' + name + (detail ? '\n          ' + String(detail).replace(/\n/g, '\n          ') : ''));
  return false;
}

function count(str, needle) {
  return str.split(needle).length - 1;
}

/* =========================================================================
 * Fixtures — real Community Applications feed entries
 * ========================================================================= */

// binhex-emby: ExtraParams --restart, a choice-list Default="yes|no", and
// several <Config> rows whose value is empty so the Default carries them.
var EMBY = {
  Name: 'binhex-emby',
  Repository: 'ghcr.io/binhex/arch-emby',
  Network: 'bridge',
  Privileged: 'false',
  ExtraParams: '--restart=unless-stopped',
  ReadMe: 'https://github.com/binhex/documentation',
  Support: 'https://forums.unraid.net/topic/index.php?topic=46382.0/',
  Project: 'https://https://emby.media/',
  Overview: 'Bringing all of your home videos, music, and photos together into one place has never been easier. Your personal Emby Server automatically converts and streams your media on-the-fly to play on any device.',
  WebUI: 'http://[IP]:[PORT:8096]',
  Icon: 'https://raw.githubusercontent.com/binhex/templates/main/unraid/binhex/images/emby-icon.png',
  Repo: "Binhex's Repository",
  CategoryList: ['MediaApp-Video', 'MediaApp-Music', 'MediaApp-Photos', 'MediaServer-Video', 'MediaServer-Music', 'MediaServer-Photos'],
  Config: [
    { '@attributes': { Name: 'Port: Web Interface', Target: '8096', Default: '8096', Mode: 'tcp',
        Description: 'This is the Web UI port for the application.', Type: 'Port', Required: 'true', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Path: /config', Target: '/config', Default: '/mnt/user/appdata/emby', Mode: 'rw',
        Description: 'This is the container path to your configuration files, e.g. databases, configuration files, logs etc.', Type: 'Path', Required: 'true', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Path: /media', Target: '/media', Default: '/mnt/user', Mode: 'rw',
        Description: 'This is the container path to your media files, e.g. movies, tv, music, pictures etc.', Type: 'Path', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Variable: ENABLE_HEALTHCHECK', Target: 'ENABLE_HEALTHCHECK', Default: 'yes|no',
        Description: 'Enable or disable healthchecks.', Type: 'Variable', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Variable: HEALTHCHECK_COMMAND', Target: 'HEALTHCHECK_COMMAND', Default: '',
        Description: 'The command or script to execute, if not specified then the script healthcheck.sh will be used (process, dns and https checking).', Type: 'Variable', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Variable: PUID', Target: 'PUID', Default: '99',
        Description: 'User ID for the running container', Type: 'Variable', Required: 'true', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Variable: PGID', Target: 'PGID', Default: '100',
        Description: 'Group ID for the running container', Type: 'Variable', Required: 'true', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Variable: UMASK', Target: 'UMASK', Default: '000',
        Description: 'UMASK for the running container', Type: 'Variable', Required: 'true', Mask: 'false' }, value: '' }
  ]
};

// wger-redis: several ExtraParams flags this converter cannot map at all
// (--health-cmd/--health-interval/--health-retries/--health-start-period/
// --health-timeout), on a named network that is not "bridge/host/none" and
// carries no "custom:" prefix either — a real shape the feed uses.
var WGER_REDIS = {
  Name: 'wger-redis',
  Repository: 'redis',
  Network: 'wger_network',
  Support: 'https://github.com/wger-project/docker/issues',
  Project: 'https://redis.io/',
  Overview: 'Redis is used as cache and Celery message broker for the wger application.',
  Icon: 'https://raw.githubusercontent.com/rorar/unraid-templates/main/templates/docker_icons/wger-logo.png',
  ExtraParams: '--health-cmd="redis-cli ping" --health-interval=10s --health-retries=5 --health-start-period=30s --health-timeout=5s',
  PostArgs: 'redis-server /usr/local/etc/redis/redis.conf',
  Repo: "rorar's Repository",
  CategoryList: ['Tools-Utilities'],
  Config: [
    { '@attributes': { Name: 'PORT', Target: '6379', Default: '6379', Mode: 'tcp',
        Description: 'Redis port', Type: 'Port', Required: 'true', Mask: 'false' }, value: '6379' },
    { '@attributes': { Name: 'REDIS_DATA', Target: '/data', Default: '/mnt/user/appdata/wger/redis/', Mode: 'rw',
        Description: 'Persistent Redis data directory', Type: 'Path', Required: 'true', Mask: 'false' }, value: '/mnt/user/appdata/wger/redis/' },
    { '@attributes': { Name: 'PUID', Target: 'PUID', Default: '99', Mode: '',
        Description: 'User ID', Type: 'Variable', Required: 'false', Mask: 'false' }, value: '99' }
  ]
};

// ABS-KoSync-Bridge: Network: host, and two Paths with no value AND no
// Default at all (so the generated-placeholder rule fires), on a
// Mode="rw,slave" that must survive verbatim.
var ABS_KOSYNC = {
  Name: 'ABS-KoSync-Bridge',
  Repository: 'ghcr.io/cporcellijr/abs-kosync-bridge:latest',
  Network: 'host',
  Support: 'https://forums.unraid.net/topic/196737-support-abs-kosync-bridge/',
  Project: 'https://github.com/cporcellijr/abs-kosync-bridge',
  ReadMe: 'https://github.com/cporcellijr/abs-kosync-bridge',
  Overview: 'ABS-KoSync Enhanced is a powerful, automated synchronization engine.',
  WebUI: 'http://[IP]:[PORT:5757]',
  Icon: 'https://github.com/cporcellijr/abs-kosync-bridge/blob/main/ABS-KOSync-Bridge.png?raw=true',
  Repo: "AcmePluto's Repository",
  CategoryList: ['Tools-Utilities', 'MediaApp-Books'],
  Config: [
    { '@attributes': { Name: 'Books', Target: '/books', Default: '', Mode: 'rw,slave',
        Description: 'Your EPUB library', Type: 'Path', Required: 'true', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Config', Target: '/data', Default: '', Mode: 'rw,slave',
        Description: 'You config storage location', Type: 'Path', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Web UI Port', Target: '5757', Default: '5757', Mode: 'tcp',
        Description: 'Container Port: 5757', Type: 'Port', Required: 'true', Mask: 'false' }, value: '5757' }
  ]
};

// OBS-NDI: a named network written as a bare Unraid custom-network name
// (br0), and a Mask="true" Variable with an empty value.
var OBS_NDI = {
  Name: 'OBS-NDI',
  Repository: 'asparon/obs-ndi',
  Network: 'br0',
  Support: 'https://forums.unraid.net/topic/145651-support-obs-ndi-qt6/',
  Project: 'https://hub.docker.com/r/asparon/obs-ndi',
  Overview: 'Docker OBS and NDI (QT6).',
  WebUI: 'http://[IP]:[PORT:6901]/',
  Icon: 'https://obsproject.com/assets/images/new_icon_small-r.png',
  Repo: "Asparon's Repository",
  CategoryList: ['GameServers', 'Tools-Utilities', 'MediaApp-Video'],
  Config: [
    { '@attributes': { Name: 'VNC', Target: '5901', Default: '5901', Mode: 'tcp',
        Description: 'Port for VNC', Type: 'Port', Required: 'false', Mask: 'false' }, value: '5901' },
    { '@attributes': { Name: 'OBS config path', Target: '/home/headless/.config/obs-studio', Default: '', Mode: 'rw',
        Description: 'path for storing the OBS config data', Type: 'Path', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'VNC password', Target: 'VNC_PW', Default: 'headless', Mode: '',
        Description: 'set your VNC password (default: headless)', Type: 'Variable', Required: 'false', Mask: 'true' }, value: '' }
  ]
};

// liquidctl: Network: none, a Device with a real value, and ExtraParams
// carrying --privileged plus a REPEATED --log-opt (proves multi-value flags
// collect into a list rather than overwriting one another).
var LIQUIDCTL = {
  Name: 'liquidctl',
  Repository: 'avpnusr/liquidctl',
  Network: 'none',
  Project: 'https://github.com/avpnusr/liquidctl-docker',
  Overview: 'Allows you to control an AIO liquid-cooler and RGB devices.',
  Icon: 'https://raw.githubusercontent.com/avpnusr/liquidctl-docker/master/img/LiquidCTL-icon.png',
  ExtraParams: '--privileged --log-opt max-size=2m --log-opt max-file=1',
  Repo: "FatzCat's Repository",
  CategoryList: ['Tools-Utilities'],
  Config: [
    { '@attributes': { Name: 'AIO USB Device ID', Target: '', Default: '',
        Description: "Enter the path to the USB BUS Device ID for your AIO.", Type: 'Device', Required: 'true', Mask: 'false' }, value: '/sys/bus/usb/devices/3-6.3' },
    { '@attributes': { Name: 'MATCH', Target: 'MATCH', Default: '',
        Description: 'Enter the name of your vendor for the AIO water-cooling here.', Type: 'Variable', Required: 'true', Mask: 'false' }, value: 'kraken' }
  ]
};

// linkstack: a Label Config row (with an empty value, which is still
// emitted, same as an empty Variable), on the default bridge network.
var LINKSTACK = {
  Name: 'linkstack',
  Repository: 'linkstackorg/linkstack:unraid',
  Network: 'bridge',
  Support: 'https://discord.gg/PtQswUmb',
  Project: 'https://linkstack.org/',
  Overview: 'Self-hosted open-source Linktree alternative.',
  WebUI: 'http://[IP]:[PORT:80]',
  Icon: 'https://i.imgur.com/qdL82EK.png',
  Repo: "IBRACORP's Repository",
  CategoryList: ['Productivity', 'Tools-Utilities'],
  Config: [
    { '@attributes': { Name: 'ServerName', Target: 'HTTP_SERVER_NAME', Default: '', Description: '', Type: 'Variable', Required: 'false', Mask: 'false' }, value: 'link.EXAMPLE.COM' },
    { '@attributes': { Name: 'Admin', Target: 'SERVER_ADMIN', Default: '', Description: '', Type: 'Label', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'HTTP Port', Target: '80', Default: '', Mode: 'tcp', Description: '', Type: 'Port', Required: 'false', Mask: 'false' }, value: '2880' }
  ]
};

// elasticsearch: the quote-aware tokeniser's proof case — ExtraParams holds
// -e "ES_JAVA_OPTS"="-Xms512m -Xmx512m", a value with a space inside quotes
// that a naive .split(' ') breaks into garbage — plus an unmapped --ulimit.
var ELASTICSEARCH = {
  Name: 'elasticsearch',
  Repository: 'elasticsearch:6.6.2',
  Network: 'bridge',
  Support: 'https://forums.unraid.net/topic/79125-support-foxxmd-elasticsearch',
  Project: 'https://www.elastic.co/',
  Overview: 'Elasticsearch with instructions for installation on Unraid.',
  WebUI: 'http://[IP]:[PORT:9200]/',
  Icon: 'https://github.com/FoxxMD/unraid-docker-templates/raw/master/elasticsearch.png',
  ExtraParams: '-e "ES_JAVA_OPTS"="-Xms512m -Xmx512m" --ulimit nofile=262144:262144',
  Repo: 'FoxxMD',
  CategoryList: ['Network-Other'],
  Config: [
    { '@attributes': { Name: 'Data', Target: '/usr/share/elasticsearch/data', Default: '/mnt/user/appdata/elasticsearch/data', Mode: 'rw',
        Description: 'Directory where ES data is persisted', Type: 'Path', Required: 'true', Mask: 'false' }, value: '/mnt/user/appdata/elasticsearch/data' },
    { '@attributes': { Name: 'REST API Port', Target: '9200', Default: '9200', Mode: 'tcp',
        Description: 'Host port exposed for REST HTTP interface.', Type: 'Port', Required: 'true', Mask: 'false' }, value: '9200' },
    { '@attributes': { Name: 'Discovery Type', Target: 'discovery.type', Default: 'single-node', Mode: '',
        Description: 'Container Variable: discovery.type', Type: 'Variable', Required: 'true', Mask: 'false' }, value: 'single-node' }
  ]
};

// Hand-built: a Device Config row with no value and no Default at all — the
// one case none of the six real fixtures above happens to carry. Not found
// verbatim in a search of the feed; built to exercise the "no sane default
// for a device node" rule the spec calls for.
var DEVICE_EMPTY = {
  Name: 'device-empty-test',
  Repository: 'example/device-empty-test',
  Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'GPU', Target: '', Default: '', Description: 'GPU device node', Type: 'Device', Required: 'false', Mask: 'false' }, value: '' }
  ]
};

// Hand-built: a Description long enough that the ~160-character truncation
// rule actually fires, with Required+Mask both set so the marker order
// (-!R then -!S) is provable in one line.
var LONG_DESC = {
  Name: 'long-desc-test',
  Repository: 'example/long-desc-test',
  Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'X', Target: 'X', Default: '1',
        Description: 'This description is deliberately written to run on for a very long time so that it comfortably passes one hundred and sixty characters and must be cut off at a word boundary somewhere in here.',
        Type: 'Variable', Required: 'true', Mask: 'true' }, value: '' }
  ]
};

var FIXTURES = [
  ['binhex-emby', EMBY], ['wger-redis', WGER_REDIS], ['ABS-KoSync-Bridge', ABS_KOSYNC],
  ['OBS-NDI', OBS_NDI], ['liquidctl', LIQUIDCTL], ['linkstack', LINKSTACK],
  ['elasticsearch', ELASTICSEARCH], ['device-empty-test', DEVICE_EMPTY], ['long-desc-test', LONG_DESC]
];

/* =========================================================================
 * A. Round-trip and form-readability, every fixture
 *
 * doc.sealed is not required to be empty. Two reasons are expected and
 * harmless, not bugs:
 *
 *   block-scalar  the Overview mapping the spec calls for is written as a
 *                 `|` block, and compose-model.js seals every block scalar
 *                 on principle (see examples/jellyfin/compose.yaml, the
 *                 schema's own worked example — it seals two, for the same
 *                 reason).
 *   escape        a double-quoted value that itself contains a literal `"`
 *                 or `\` (a JSON blob some template stuffed into a default
 *                 Variable value is the real case this hits) needs proper
 *                 backslash escaping, and the parser deliberately locks any
 *                 quoted value that needs one rather than half-understand
 *                 an escape table — see compose-model.js's own comment
 *                 beside `seal(ctx, at, at + 1, 'escape')`. The file still
 *                 round-trips and the form still builds; only that one
 *                 field shows as locked.
 *
 * What must never appear is any OTHER reason — an anchor, alias, merge key
 * or flow collection would mean this converter wrote something the form
 * cannot edit at all, which is the actual bug this guards against.
 * ========================================================================= */

console.log('\nA. Round-trip and readability');
FIXTURES.forEach(function (pair) {
  var label = pair[0], app = pair[1];
  var r = CA.convert(app);
  var doc = Y.parse(r.yaml);
  var back = Y.serialise(doc);
  ok(label + ': round-trips byte for byte', back === r.yaml,
     back !== r.yaml ? 'first mismatch near: ' + JSON.stringify(back.slice(0, 200)) : null);
  ok(label + ': ends in exactly one newline', /[^\n]\n$/.test(r.yaml) || r.yaml === '\n');
  var form = Y.buildForm(doc);
  ok(label + ': buildForm().ok is true', form.ok === true);
  var badSeals = doc.sealed.filter(function (s) { return s.reason !== 'block-scalar' && s.reason !== 'escape'; });
  ok(label + ': nothing sealed except the overview block and escaped values', badSeals.length === 0, JSON.stringify(badSeals));
});

/* =========================================================================
 * B. Name normalisation
 * ========================================================================= */

console.log('\nB. Name normalisation');
var VALID_NAME = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
FIXTURES.forEach(function (pair) {
  var r = CA.convert(pair[1]);
  ok(pair[0] + ': name matches staxx_valid_name()', VALID_NAME.test(r.name), r.name);
  ok(pair[0] + ': name is 63 characters or fewer', r.name.length <= 63);
  ok(pair[0] + ': name has no ".."', r.name.indexOf('..') === -1);
  ok(pair[0] + ': service key equals the name', r.service === r.name);
});

ok('a name with only invalid characters falls back to imported-app', CA.normaliseName('!!!') === 'imported-app');
ok('a name starting with an underscore gets an app- prefix', VALID_NAME.test(CA.normaliseName('_test')) && CA.normaliseName('_test').charAt(0) === 'a');
ok('a 90-character name is truncated to 63', CA.normaliseName(new Array(91).join('a')).length === 63);
ok('repeated dots collapse to one', CA.normaliseName('my..app').indexOf('..') === -1);
ok('mixed-case input is lowercased', CA.normaliseName('MyApp') === 'myapp');

/* =========================================================================
 * C. The mapping table lands where it says — binhex-emby
 * ========================================================================= */

console.log('\nC. Mapping table — binhex-emby');
var embyR = CA.convert(EMBY);
var embyY = embyR.yaml;
ok('image: comes from Repository', embyY.indexOf('image: ghcr.io/binhex/arch-emby') >= 0);
// binhex-emby's Name has no capitals, so this also happens to equal the
// normalised name — the real proof that container_name is Name verbatim,
// not the sanitised form, is section N below.
ok('container_name: equals Name verbatim', embyY.indexOf('container_name: binhex-emby') >= 0);
ok('bridge network emits no network_mode, but an explicit default network',
   embyY.indexOf('network_mode') === -1 && embyY.indexOf('    networks:\n      - default\n') >= 0);
ok('ExtraParams --restart=unless-stopped becomes restart: unless-stopped', embyY.indexOf('restart: unless-stopped') >= 0);
ok('the empty-valued Port falls back to Default 8096', embyY.indexOf('"8096:8096"') >= 0);
ok('the empty-valued /config Path falls back to its Default', embyY.indexOf('/mnt/user/appdata/emby:/config') >= 0);
ok('a Path Mode of plain rw gets no :suffix', embyY.indexOf('/mnt/user:/media') >= 0 && embyY.indexOf('/mnt/user:/media:rw') === -1);
ok('the yes|no choice-list default picks the first option', embyY.indexOf('ENABLE_HEALTHCHECK: "yes"') >= 0);
ok('an empty Variable with an empty Default is still emitted, empty', embyY.indexOf('HEALTHCHECK_COMMAND: ""') >= 0);
// PLAN_105: a stack's picture is derived from its services, never stated —
// the catalogue's Icon lands in the SERVICE's x-unraid block, not the
// stack's, even though a converted file is always single-service.
ok('Icon becomes service x-unraid.icon', embyY.indexOf('      icon: https://raw.githubusercontent.com/binhex/templates') >= 0);
// Stack-level keys sit at 2-space indent; only a service's own x-unraid
// block (6-space indent) may carry icon, so a bare "  icon:" line would mean
// one leaked back onto the stack.
ok('no stack-level icon is ever written', embyY.indexOf('\n  icon:') === -1);
ok('CategoryList[0] MediaApp-Video normalises to MediaApp:Video', embyY.indexOf('category: MediaApp:Video') >= 0);
ok('Project is carried through unchanged, typo and all', embyY.indexOf('project: https://https://emby.media/') >= 0);
ok('Support becomes stack x-unraid.support', embyY.indexOf('support: https://forums.unraid.net') >= 0);
ok('ReadMe becomes stack x-unraid.readme', embyY.indexOf('readme: https://github.com/binhex/documentation') >= 0);
ok('Overview becomes a | block', embyY.indexOf('overview: |') >= 0);
ok('WebUI passes through unchanged, brackets and all', embyY.indexOf('webui: "http://[IP]:[PORT:8096]"') >= 0);
ok('Repo becomes author when Author is absent', embyY.indexOf("author: Binhex's Repository") >= 0);
ok('x-unraid: version: 1 is always present', /x-unraid:\n {2}version: 1/.test(embyY));

// -!R / -!S land only where Required/Mask said, and nowhere else.
var reqCount = 0, mineCount = 0;
EMBY.Config.forEach(function (c) { if (c['@attributes'].Required === 'true') reqCount++; if (c['@attributes'].Mask === 'true') mineCount++; });
ok('-!R appears exactly once per Required=true row', count(embyY, '-!R') === reqCount, count(embyY, '-!R') + ' vs ' + reqCount);
ok('-!S appears exactly once per Mask=true row (none, here)', count(embyY, '-!S') === mineCount);

/* =========================================================================
 * D. Network handling
 * ========================================================================= */

console.log('\nD. Network handling');
var wgerY = CA.convert(WGER_REDIS).yaml;
ok('a named network with no custom: prefix still becomes networks:', wgerY.indexOf('networks:\n      - wger_network') >= 0);
ok('the named network is declared external: true at the top level', /\nnetworks:\n {2}wger_network:\n {4}external: true\n?$/.test(wgerY));
ok('a named network produces a note that it must already exist', CA.convert(WGER_REDIS).notes.some(function (w) { return /wger_network/.test(w); }));

var absY = CA.convert(ABS_KOSYNC).yaml;
ok('Network: host becomes network_mode: host', absY.indexOf('network_mode: host') >= 0);
ok('host mode emits no networks: block', absY.indexOf('networks:') === -1);

var obsY = CA.convert(OBS_NDI).yaml;
ok('a bare custom-network name (br0) becomes networks:', obsY.indexOf('networks:\n      - br0') >= 0);
ok('br0 is declared external at the top level too', /\n {2}br0:\n {4}external: true/.test(obsY));

var liquidY = CA.convert(LIQUIDCTL).yaml;
ok('Network: none becomes network_mode: none', liquidY.indexOf('network_mode: none') >= 0);

var linkY = CA.convert(LINKSTACK).yaml;
ok('bridge network on linkstack writes the default network, nothing else',
   linkY.indexOf('network_mode') === -1 && linkY.indexOf('    networks:\n      - default\n') >= 0);

// No Network field at all — same meaning as "bridge", said the same way.
var noNetR = CA.convert({ Name: 'no-network-test', Repository: 'example/no-network-test' });
ok('no Network field at all still writes the default network',
   noNetR.yaml.indexOf('    networks:\n      - default\n') >= 0);
ok('...and no top-level networks: declaration — compose creates default itself',
   noNetR.yaml.indexOf('\nnetworks:\n') === -1);

// Unraid's Container mode: share another container's/service's network stack
// entirely — a mode, not a named network (PLAN_64 section 4b).
var containerOutsideR = CA.convert({
  Name: 'container-outside-test', Repository: 'example/container-outside-test',
  Network: 'container:openvpn-client'
});
ok('Container mode naming a container outside this stack becomes network_mode: container:<name>',
   containerOutsideR.yaml.indexOf('network_mode: container:openvpn-client') >= 0);
ok('...and emits no networks: block', containerOutsideR.yaml.indexOf('networks:') === -1);
ok('...and a note that the target container must already exist',
   containerOutsideR.notes.some(function (w) { return /openvpn-client/.test(w); }));

// Same field, but the target is one of this stack's own services — compose
// works out the start order itself for this form, so it gets no such note.
var containerInsideR = CA.convert({
  Name: 'container-inside-test', Repository: 'example/container-inside-test',
  Network: 'container:sibling-service'
}, { stackServices: ['sibling-service'] });
ok('Container mode naming a service already in this stack becomes network_mode: service:<name>',
   containerInsideR.yaml.indexOf('network_mode: service:sibling-service') >= 0);
ok('...and produces no "must already exist" note for it',
   !containerInsideR.notes.some(function (w) { return /sibling-service/.test(w); }));

// A fixed IP has nowhere to live under a shared network stack, exactly as
// for host/none — dropped with a warning naming it, same mechanism reused.
var containerFixedIpR = CA.convert({
  Name: 'container-fixedip-test', Repository: 'example/container-fixedip-test',
  Network: 'container:openvpn-client', MyIP: '192.168.202.66'
});
ok('a fixed IP under Container mode is dropped with a warning naming it',
   containerFixedIpR.warnings.some(function (w) { return /192\.168\.202\.66/.test(w); }));
ok('...and never written as a field', containerFixedIpR.yaml.indexOf('ipv4_address') === -1);

/* =========================================================================
 * E. Path / Port / Device / Label edge rules
 * ========================================================================= */

console.log('\nE. Path/Port/Device/Label edge rules');
ok('a Path with no value and no Default gets a generated placeholder', absY.indexOf('/mnt/user/appdata/abs-kosync-bridge/books:/books:rw,slave') >= 0);
ok('the generated-path case produces a note naming it', CA.convert(ABS_KOSYNC).notes.some(function (w) { return /had no value/.test(w) && /Books/.test(w); }));
ok('Mode "rw,slave" passes through verbatim, not collapsed to :ro', absY.indexOf(':rw,slave') >= 0);

var deviceEmptyR = CA.convert(DEVICE_EMPTY);
ok('a Device with no value at all is skipped entirely', deviceEmptyR.yaml.indexOf('devices:') === -1);
ok('the skipped Device produces a warning', deviceEmptyR.warnings.some(function (w) { return /device/i.test(w) && /GPU/.test(w); }));

ok('a real Device value is emitted bare (no quotes)', liquidY.indexOf('- /sys/bus/usb/devices/3-6.3') >= 0 && liquidY.indexOf('"/sys/bus/usb/devices/3-6.3"') === -1);

ok('a Label Config row becomes a labels: entry', linkY.indexOf('labels:') >= 0 && linkY.indexOf('SERVER_ADMIN: ""') >= 0);
ok('an empty Label value is still emitted, quoted empty', linkY.indexOf('SERVER_ADMIN: ""') >= 0);

var obsWarnings = CA.convert(OBS_NDI).warnings;
ok('Mask=true with an empty value still resolves to its Default and gets -!S', obsY.indexOf('VNC_PW: "headless"') >= 0 && / -!S/.test(obsY));
ok('Mask=true does not also add -!R when Required is false', (obsY.match(/VNC_PW:[^\n]*/) || [''])[0].indexOf('-!R') === -1);

/* =========================================================================
 * F. ExtraParams
 * ========================================================================= */

console.log('\nF. ExtraParams');
var liquidR = CA.convert(LIQUIDCTL);
ok('--privileged from ExtraParams sets privileged: true even though Privileged="false" is absent from this fixture',
   liquidY.indexOf('privileged: true') >= 0);
ok('a repeated --log-opt collects into a logging.options list, not one overwriting the other',
   liquidY.indexOf('max-size: "2m"') >= 0 && liquidY.indexOf('max-file: "1"') >= 0);
ok('logging: sits among the block keys, after the per-setting blocks', liquidY.indexOf('logging:') > liquidY.indexOf('environment:') || liquidY.indexOf('environment:') === -1);

var wgerR = CA.convert(WGER_REDIS);
['--health-cmd', '--health-interval', '--health-retries', '--health-start-period', '--health-timeout'].forEach(function (flag) {
  ok('unmapped flag ' + flag + ' is reported in warnings', wgerR.warnings.some(function (w) { return w.indexOf(flag) >= 0; }));
});
ok('PostArgs becomes command:', wgerY.indexOf('command: redis-server /usr/local/etc/redis/redis.conf') >= 0);

var elasticR = CA.convert(ELASTICSEARCH);
var elasticY = elasticR.yaml;
ok('the quote-aware tokeniser keeps "-Xms512m -Xmx512m" as one value, not split on its inner space',
   elasticY.indexOf('ES_JAVA_OPTS: "-Xms512m -Xmx512m"') >= 0);
ok('-e maps to an environment: entry appended after the Config variables',
   elasticY.indexOf('ES_JAVA_OPTS') > elasticY.indexOf('discovery.type'));
ok('--ulimit (unmapped) is reported in warnings, flag and value both',
   elasticR.warnings.some(function (w) { return w.indexOf('--ulimit') >= 0 && w.indexOf('262144') >= 0; }));

var dropTest = CA.convert({
  Name: 'drop-test', Repository: 'example/drop-test', Network: 'bridge',
  ExtraParams: '-d --rm --name foo --gpus all -v /host:/container'
});
ok('mechanics (-d, --rm, --name) are dropped with one combined note naming them',
   dropTest.notes.some(function (w) { return /-d/.test(w) && /--rm/.test(w) && /--name/.test(w); }));
ok('the dropped --name\'s value ("foo") is consumed, not left as a stray warning',
   !dropTest.warnings.some(function (w) { return /^The extra Docker option "foo"/.test(w); }));
ok('--gpus is never guessed at — it is reported, not mapped', dropTest.warnings.some(function (w) { return w.indexOf('--gpus=all') >= 0; }));
// dropTest.yaml does contain the substring "volumes:" now — inside the -v
// hint's own prose, in the warnings comment block — so the real invariant is
// no actual `volumes:` mapping key, not a bare substring search.
ok('-v is reported, not merged into volumes:', dropTest.warnings.some(function (w) { return w.indexOf('-v=/host:/container') >= 0; }) && !/^ {4}volumes:/m.test(dropTest.yaml));

/* =========================================================================
 * G. Comment truncation and marker order
 * ========================================================================= */

console.log('\nG. Comment truncation and markers');
var longR = CA.convert(LONG_DESC);
var longLine = (longR.yaml.match(/X: [^\n]*/) || [''])[0];
ok('a long Description is truncated with a trailing ellipsis', longLine.indexOf('…') >= 0);
ok('the truncated comment stays close to 160 characters, not the full original', longLine.length < LONG_DESC.Config[0]['@attributes'].Description.length);
ok('-!R comes before -!S when both apply', /…\s*-!R\s*-!S\s*$/.test(longLine), longLine);

/* =========================================================================
 * H. Flag hints, and warnings surviving as comments in the file
 * ========================================================================= */

console.log('\nH. Flag hints and the warnings-as-comments block');
var gpuR = CA.convert({
  Name: 'gpu-test', Repository: 'example/gpu-test', Network: 'bridge', ExtraParams: '--gpus all'
});
ok('--gpus names its compose equivalent in the warning',
   gpuR.warnings.some(function (w) { return w.indexOf('--gpus=all') >= 0 && w.indexOf('deploy.resources.reservations.devices') >= 0; }));

var unhintedR = CA.convert({
  Name: 'unhinted-test', Repository: 'example/unhinted-test', Network: 'bridge', ExtraParams: '--made-up-flag foo'
});
ok('an unmapped, unhinted flag keeps the plain "could not be translated" wording',
   unhintedR.warnings.some(function (w) {
     return w.indexOf('--made-up-flag=foo') >= 0 &&
       /could not be translated to a Compose setting and was not applied\. Check whether it is still needed\.$/.test(w);
   }));

ok('a converted app with warnings carries them as comment lines in yaml',
   gpuR.warnings.length > 0 &&
   gpuR.yaml.indexOf('# Could not be translated automatically:') >= 0 &&
   gpuR.yaml.indexOf('deploy.resources.reservations.devices') >= 0);

var embyNoWarn = CA.convert(EMBY);
ok('binhex-emby converts with no warnings at all (precondition for the next check)', embyNoWarn.warnings.length === 0);
ok('binhex-emby also carries no notes — every value it needed came from its own Default',
   embyNoWarn.notes.length === 0);
ok('an app with no warnings has no heading and no stray comment block',
   embyNoWarn.yaml.indexOf('Could not be translated automatically') === -1);
ok('a clean conversion has no comment block at all', !/^# Filled in for you/m.test(embyNoWarn.yaml));
ok('a clean conversion does not open the file with a stray bare "#" line', embyNoWarn.yaml.indexOf('#') !== 0);
ok('a warnings-only conversion opens straight on its own heading, no leading bare "#"',
   gpuR.yaml.indexOf('# Could not be translated automatically:') === 0);

/* =========================================================================
 * H2. Uppercase repository-path note
 *
 * Docker rejects a repository name outright if it contains an uppercase
 * letter — the registry host and the tag are exempt, since a host is
 * case-insensitive and a tag is legitimately mixed-case. Every case below
 * checks the image line is written through byte-for-byte unchanged, so a
 * future "helpful" lowercasing cannot slip in unnoticed.
 * ========================================================================= */

console.log('\nH2. Uppercase repository-path note');

var upperOwnerR = CA.convert({
  Name: 'lm-studio-test', Repository: 'MarkSupinski/lm-studio-headless:latest', Network: 'bridge'
});
ok('an uppercase owner/repository segment produces the note',
   upperOwnerR.notes.some(function (w) { return /MarkSupinski\/lm-studio-headless:latest/.test(w) && /lowercase/.test(w); }));
ok('the image line is written unchanged (owner uppercase case)',
   upperOwnerR.yaml.indexOf('image: MarkSupinski/lm-studio-headless:latest') >= 0);

var upperHostR = CA.convert({
  Name: 'ripuz-test', Repository: 'ghcr.io/Suvir0/ripuz:latest', Network: 'bridge'
});
ok('an uppercase segment after a registry host still produces the note',
   upperHostR.notes.some(function (w) { return /ghcr\.io\/Suvir0\/ripuz:latest/.test(w) && /lowercase/.test(w); }));
ok('the image line is written unchanged (host+path case)',
   upperHostR.yaml.indexOf('image: ghcr.io/Suvir0/ripuz:latest') >= 0);

var upperTagOnlyR = CA.convert({
  Name: 'code-server-test', Repository: 'linuxserver/code-server:V1.2', Network: 'bridge'
});
ok('an uppercase TAG only produces no lowercase-repository note',
   !upperTagOnlyR.notes.some(function (w) { return /lowercase/.test(w); }));
ok('the image line is written unchanged (tag-only case)',
   upperTagOnlyR.yaml.indexOf('image: linuxserver/code-server:V1.2') >= 0);

var upperHostOnlyR = CA.convert({
  Name: 'ghcr-host-test', Repository: 'GHCR.io/owner/app', Network: 'bridge'
});
ok('an uppercase registry HOST only produces no lowercase-repository note',
   !upperHostOnlyR.notes.some(function (w) { return /lowercase/.test(w); }));
ok('the image line is written unchanged (host-only case)',
   upperHostOnlyR.yaml.indexOf('image: GHCR.io/owner/app') >= 0);

var lowerR = CA.convert({
  Name: 'redis-test', Repository: 'redis:7', Network: 'bridge'
});
ok('an ordinary all-lowercase image produces no lowercase-repository note',
   !lowerR.notes.some(function (w) { return /lowercase/.test(w); }));
ok('the image line is written unchanged (all-lowercase case)',
   lowerR.yaml.indexOf('image: redis:7') >= 0);

/* =========================================================================
 * H3. warnings vs notes, and a configurable appdata root
 *
 * warnings is for something that did NOT get applied; notes is for
 * something that DID convert but is worth a glance. A converter this is
 * uncertain about should say so quietly, not read as broken.
 * ========================================================================= */

console.log('\nH3. warnings vs notes, and a configurable appdata root');

// A single blank Path and nothing else — nothing here can fail to convert,
// so this proves a good conversion gets the "filled in for you" heading
// only, never the "could not be translated" one.
var PATH_ONLY_TEST = {
  Name: 'path-only-test', Repository: 'example/path-only-test', Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'Config', Target: '/config', Default: '', Description: '',
        Type: 'Path', Required: 'false', Mask: 'false' }, value: '' }
  ]
};

var pathOnlyR = CA.convert(PATH_ONLY_TEST);
ok('a template with only a blank Path has no warnings', pathOnlyR.warnings.length === 0);
ok('...and exactly one note', pathOnlyR.notes.length === 1);
ok('its comment block carries only the "Filled in for you" heading',
   pathOnlyR.yaml.indexOf('# Filled in for you') >= 0 &&
   pathOnlyR.yaml.indexOf('# Could not be translated automatically:') === -1);
ok('a notes-only conversion opens straight on its own heading, no leading bare "#"',
   pathOnlyR.yaml.indexOf('# Filled in for you') === 0);
ok('omitting opts falls back to /mnt/user/appdata/',
   pathOnlyR.yaml.indexOf('/mnt/user/appdata/path-only-test/config') >= 0);

var customRootR = CA.convert(PATH_ONLY_TEST, { appdataRoot: '/mnt/cache/appdata/' });
ok('convert(app, { appdataRoot }) uses it for the placeholder path',
   customRootR.yaml.indexOf('/mnt/cache/appdata/path-only-test/config') >= 0);

var noSlashRootR = CA.convert(PATH_ONLY_TEST, { appdataRoot: '/mnt/cache/appdata' });
ok('a root with no trailing slash is normalised to exactly one',
   noSlashRootR.yaml.indexOf('/mnt/cache/appdata/path-only-test/config') >= 0);

/* =========================================================================
 * H4. A PHP-shaped template — empty XML elements decode as arrays
 *
 * simplexml_load_file() plus json_decode(json_encode(...)) — how Import.php
 * reads a template — turns an empty element written <WebUI/> into an empty
 * ARRAY, not an empty string. PHP treats that as falsy; JavaScript treats an
 * empty array as truthy. Left unguarded that writes command: "" into most
 * real templates, silently overriding the image's own start-up command —
 * proven against the 85 templates on the box, not inferred. Import.php
 * coerces this away before a template ever reaches the browser, but this is
 * the assertion that would have caught the bug in the first place, so it
 * belongs in the suite that owns the converter, not only the one that owns
 * the PHP fix.
 * ========================================================================= */

console.log('\nH4. A PHP-shaped template — empty elements decode as arrays');
var PHP_EMPTY_ELEMENTS = {
  Name: 'php-shaped-test',
  Repository: 'example/php-shaped-test',
  Network: 'bridge',
  WebUI: [],
  Icon: [],
  Overview: [],
  Support: [],
  ReadMe: [],
  Project: [],
  PostArgs: [],
  ExtraParams: [],
  Category: [],
  Config: [
    { '@attributes': { Name: 'PUID', Target: 'PUID', Default: '99', Description: 'User ID',
        Type: 'Variable', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var phpShapedR = CA.convert(PHP_EMPTY_ELEMENTS);
ok('an empty PostArgs array produces no command: line', phpShapedR.yaml.indexOf('command:') === -1);
ok('an empty WebUI array produces no webui: line', phpShapedR.yaml.indexOf('webui:') === -1);
ok('an empty Icon array produces no empty icon: line', phpShapedR.yaml.indexOf('icon: ""') === -1);
ok('an empty Category array produces no empty category: line', phpShapedR.yaml.indexOf('category: ""') === -1);
// Repository/Project/Support/ReadMe/Author/Repo all use the same bare-
// truthiness-turned-scalarPresent() fix (11.5's first bullet) — same empty-
// array-is-truthy shape as PostArgs above.
ok('an empty Support array produces no support: line', phpShapedR.yaml.indexOf('support:') === -1);
ok('an empty ReadMe array produces no readme: line', phpShapedR.yaml.indexOf('readme:') === -1);
ok('an empty Project array produces no project: line', phpShapedR.yaml.indexOf('project:') === -1);

var PHP_EMPTY_REPOSITORY = {
  Name: 'php-shaped-repo-test',
  Repository: [],
  Network: 'bridge'
};
var phpEmptyRepoR = CA.convert(PHP_EMPTY_REPOSITORY);
ok('an empty Repository array falls back to the stack name as the image',
   phpEmptyRepoR.yaml.indexOf('image: php-shaped-repo-test') >= 0);
ok('...and still reports that the template named no image',
   phpEmptyRepoR.notes.some(function (n) { return n.indexOf('has no image name') >= 0; }));

var PHP_EMPTY_AUTHOR = {
  Name: 'php-shaped-author-test',
  Repository: 'example/php-shaped-author-test',
  Author: [],
  Repo: []
};
var phpEmptyAuthorR = CA.convert(PHP_EMPTY_AUTHOR);
ok('empty Author and Repo arrays produce no author: line', phpEmptyAuthorR.yaml.indexOf('author:') === -1);

/* =========================================================================
 * H5. Item 13.2 — project/support written per service, not stack-only
 *
 * The service-level x-unraid keys exist so a multi-service stack can give
 * each service its own project page instead of every service sharing the
 * one written at stack level.
 * ========================================================================= */

console.log('\nH5. Project/support written at both stack and service level');
var LINKED_APP = {
  Name: 'linked-app-test',
  Repository: 'example/linked-app-test',
  Project: 'https://example.org/project',
  Support: 'https://example.org/support',
  ReadMe: 'https://example.org/readme',
  WebUI: 'http://[IP]:1234'
};
var linkedR = CA.convert(LINKED_APP);
var linkedDoc = Y.parse(linkedR.yaml);
ok('the stack-level x-unraid block still carries project/support/readme',
   linkedR.yaml.indexOf('  project: https://example.org/project') >= 0 &&
   linkedR.yaml.indexOf('  support: https://example.org/support') >= 0 &&
   linkedR.yaml.indexOf('  readme: https://example.org/readme') >= 0);
ok('the service-level x-unraid block also carries its own project/support',
   linkedR.yaml.indexOf('      project: https://example.org/project') >= 0 &&
   linkedR.yaml.indexOf('      support: https://example.org/support') >= 0);
ok('the service-level block has no readme key — that stays stack-only',
   !/^\s{6}readme:/m.test(linkedR.yaml));
ok('the file still round-trips byte for byte with the service-level keys added',
   Y.serialise(linkedDoc) === linkedR.yaml);

var UNLINKED_APP = { Name: 'unlinked-app-test', Repository: 'example/unlinked-app-test' };
var unlinkedR = CA.convert(UNLINKED_APP);
ok('no WebUI/Project/Support at all produces no service-level x-unraid block',
   !/^\s{4}x-unraid:/m.test(unlinkedR.yaml));

/* =========================================================================
 * H6. isSafeBare()/dq() and keyOut() — the remaining 11.5 bullets
 * ========================================================================= */

console.log('\nH6. Newline handling and YAML-keyword keys');
var NEWLINE_DEFAULT = {
  Name: 'newline-default-test',
  Repository: 'example/newline-default-test',
  Config: [
    { '@attributes': { Name: 'Variable: NOTE', Target: 'NOTE', Default: 'line one\nline two',
        Description: 'A multi-line default.', Type: 'Variable', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var newlineR = CA.convert(NEWLINE_DEFAULT);
var newlineDoc = Y.parse(newlineR.yaml);
ok('a Default containing a real newline is collapsed onto one quoted line',
   newlineR.yaml.indexOf('NOTE: "line one line two"') >= 0);
ok('the collapsed value still round-trips byte for byte',
   Y.serialise(newlineDoc) === newlineR.yaml);

var YAML_KEYWORD_TARGET = {
  Name: 'keyword-target-test',
  Repository: 'example/keyword-target-test',
  Config: [
    { '@attributes': { Name: 'Variable: yes', Target: 'yes', Default: 'on',
        Description: 'A variable literally named yes.', Type: 'Variable', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var keywordR = CA.convert(YAML_KEYWORD_TARGET);
ok('a Target literally "yes" is quoted as a key, not written bare',
   keywordR.yaml.indexOf('"yes": "on"') >= 0);

/* =========================================================================
 * I. Bulk sanity — every app in the live feed, if it is on disk
 *
 * Skipped entirely when the feed is not present, so the checked-in suite
 * never depends on the 24MB file. This is the case that actually finds a
 * bug: nine thousand real templates hit every branch above at once.
 * ========================================================================= */

console.log('\nI. Bulk sanity (skipped unless the feed is on disk)');
var FEED = 'Z:/!!SYSTEMP/claude/D-----Working-Projects---VSCode-MyWSpaces-Unraid-Compose-Rev2/0ac2bafa-a791-48cc-8af2-aaad9ed618ba/scratchpad/applicationFeed.json';
if (fs.existsSync(FEED)) {
  var feed = JSON.parse(fs.readFileSync(FEED, 'utf8'));
  // The feed is {apps: <count>, applist: [...], ...} — applist is the array
  // of entries; "apps" is just the count, and Array.isArray(feed) is false.
  var apps = Array.isArray(feed) ? feed : (feed.applist || feed.applications || []);
  var total = 0, roundtripFail = 0, formFail = 0, badSealFail = 0, thrown = 0;
  apps.forEach(function (app) {
    total++;
    try {
      var r = CA.convert(app);
      var doc = Y.parse(r.yaml);
      if (Y.serialise(doc) !== r.yaml) roundtripFail++;
      var form = Y.buildForm(doc);
      if (!form.ok) formFail++;
      if (doc.sealed.some(function (s) { return s.reason !== 'block-scalar' && s.reason !== 'escape'; })) badSealFail++;
    } catch (e) {
      thrown++;
    }
  });
  ok('every one of ' + total + ' feed apps round-trips byte for byte', roundtripFail === 0, roundtripFail + ' failed');
  ok('every one of ' + total + ' feed apps builds a readable form', formFail === 0, formFail + ' failed');
  ok('none of ' + total + ' feed apps seal anything but the overview block or an escaped value', badSealFail === 0, badSealFail + ' failed');
  ok('convert() never throws on any of ' + total + ' feed apps', thrown === 0, thrown + ' threw');
} else {
  console.log('  (skipped — feed not found at ' + FEED + ')');
}

/* =========================================================================
 * J. Fixed address and hardware address carried across on import
 *
 * MyIP/MyMAC pin a container's address on a named network. Only a named
 * network has an interface to hang an address on, so host/none/bridge drop
 * it with a warning rather than write it somewhere compose cannot use it;
 * a malformed value is refused the same way rather than written half-baked.
 * ========================================================================= */

console.log('\nJ. Fixed address and hardware address on import');

// 1. A fixed IP on a named network writes the map form.
var FIXEDIP_ONLY = {
  Name: 'fixedip-only-test', Repository: 'example/fixedip-only-test',
  Network: 'br0.2', MyIP: '192.168.202.66'
};
var fixedIpOnlyY = CA.convert(FIXEDIP_ONLY).yaml;
ok('a fixed IP on a named network writes the map form',
   fixedIpOnlyY.indexOf('    networks:\n      br0.2:\n        ipv4_address: 192.168.202.66\n') >= 0);
ok('the stack-level declaration is unchanged',
   /\nnetworks:\n {2}br0\.2:\n {4}external: true\n?$/.test(fixedIpOnlyY));

// 2. MyIP and MyMAC together write both keys, in that order, and no
// service-level mac_address line duplicates the one in the map.
var FIXEDIP_AND_MAC = {
  Name: 'fixedip-and-mac-test', Repository: 'example/fixedip-and-mac-test',
  Network: 'br0.2', MyIP: '192.168.202.66', MyMAC: '02:42:ac:11:00:02'
};
var fixedIpMacY = CA.convert(FIXEDIP_AND_MAC).yaml;
ok('MyIP and MyMAC together write both keys, ipv4_address then mac_address',
   fixedIpMacY.indexOf('    networks:\n      br0.2:\n        ipv4_address: 192.168.202.66\n        mac_address: 02:42:ac:11:00:02\n') >= 0);
ok('no service-level mac_address line duplicates the one already in the map',
   fixedIpMacY.indexOf('\n    mac_address:') === -1);

// 3. A named network with neither address still keeps the plain list form —
// the regression that would otherwise hit 53 of the 85 measured templates.
var FIXEDIP_NEITHER = {
  Name: 'fixedip-neither-test', Repository: 'example/fixedip-neither-test',
  Network: 'br0.2'
};
var fixedIpNeitherY = CA.convert(FIXEDIP_NEITHER).yaml;
ok('a named network with neither address still writes the plain list form',
   fixedIpNeitherY.indexOf('    networks:\n      - br0.2\n') >= 0);
ok('...and never the map form',
   fixedIpNeitherY.indexOf('ipv4_address') === -1 && fixedIpNeitherY.indexOf('mac_address') === -1);

// 4. MyIP on network_mode: host has no interface to hang it on, so it is
// dropped, warned about by name, and never written — not even the digits,
// since naming the value would not help here the way it does for a typo.
var FIXEDIP_HOST = {
  Name: 'fixedip-host-test', Repository: 'example/fixedip-host-test',
  Network: 'host', MyIP: '192.168.202.66'
};
var fixedIpHostR = CA.convert(FIXEDIP_HOST);
var fixedIpHostY = fixedIpHostR.yaml;
ok('MyIP on network_mode: host is dropped with a warning naming it',
   fixedIpHostR.warnings.some(function (w) { return /fixed ip|MyIP/i.test(w) && /host/i.test(w); }));
ok('network_mode: host is still written', fixedIpHostY.indexOf('network_mode: host') >= 0);
// "No address anywhere in the output" means never written as a field a
// compose parser would read — not that the warning comment above may not
// name the value. Naming it there is the point: it lets the user see which
// address it was, same as the malformed-value warning does.
ok('the dropped address is never written as a field, only named in the warning comment',
   fixedIpHostY.indexOf('ipv4_address') === -1 && fixedIpHostY.indexOf('networks:') === -1);

// 5. A malformed address is refused, not written — and the network still
// comes out in the plain list form rather than a half-written map.
var FIXEDIP_BAD_IP = {
  Name: 'fixedip-bad-ip-test', Repository: 'example/fixedip-bad-ip-test',
  Network: 'br0.2', MyIP: 'not-an-ip'
};
var badIpR = CA.convert(FIXEDIP_BAD_IP);
ok('a malformed MyIP is refused with a warning naming the value',
   badIpR.warnings.some(function (w) { return w.indexOf('not-an-ip') >= 0; }));
ok('...and the network still comes out in the plain list form, not a half-written map',
   badIpR.yaml.indexOf('    networks:\n      - br0.2\n') >= 0 && badIpR.yaml.indexOf('ipv4_address') === -1);

var FIXEDIP_BAD_MAC = {
  Name: 'fixedip-bad-mac-test', Repository: 'example/fixedip-bad-mac-test',
  Network: 'br0.2', MyMAC: 'not-a-mac'
};
var badMacR = CA.convert(FIXEDIP_BAD_MAC);
ok('a malformed MyMAC is refused with a warning naming the value',
   badMacR.warnings.some(function (w) { return w.indexOf('not-a-mac') >= 0; }));
ok('...and the network still comes out in the plain list form, not a half-written map',
   badMacR.yaml.indexOf('    networks:\n      - br0.2\n') >= 0 && badMacR.yaml.indexOf('mac_address') === -1);

// 6. MyMAC with no named network falls back to the service-level
// mac_address line — a MAC can be pinned even on the default bridge network,
// unlike an IP, which that network hands out itself.
var FIXEDIP_MAC_NO_NETWORK = {
  Name: 'fixedip-mac-no-network-test', Repository: 'example/fixedip-mac-no-network-test',
  Network: 'bridge', MyMAC: '02:42:ac:11:00:02'
};
var macNoNetY = CA.convert(FIXEDIP_MAC_NO_NETWORK).yaml;
ok('MyMAC with no named network falls back to the service-level mac_address line',
   macNoNetY.indexOf('\n    mac_address: 02:42:ac:11:00:02\n') >= 0);

// 7. MyMAC and --mac-address in ExtraParams disagree: the explicit
// ExtraParams instruction wins, is written once (into the map, not also as
// the service-level fallback), and a warning says the two disagreed.
var FIXEDIP_MAC_CONFLICT = {
  Name: 'fixedip-mac-conflict-test', Repository: 'example/fixedip-mac-conflict-test',
  Network: 'br0.2', MyMAC: '02:42:ac:11:00:02', ExtraParams: '--mac-address=02:42:ac:99:99:99'
};
var conflictR = CA.convert(FIXEDIP_MAC_CONFLICT);
var conflictY = conflictR.yaml;
ok('ExtraParams --mac-address wins over MyMAC and is written into the map form',
   conflictY.indexOf('      br0.2:\n        mac_address: 02:42:ac:99:99:99') >= 0);
ok('no service-level mac_address line duplicates it',
   conflictY.indexOf('\n    mac_address:') === -1);
ok('a warning says the two disagreed',
   conflictR.warnings.some(function (w) { return w.indexOf('02:42:ac:11:00:02') >= 0 && w.indexOf('02:42:ac:99:99:99') >= 0; }));

// 8. The output still round-trips — this file's standing contract — and
// buildForm() finds the address and hardware address as editable fields on
// the network row, same as any other value a hand-written file could carry.
var rtR = CA.convert(FIXEDIP_AND_MAC);
var rtDoc = Y.parse(rtR.yaml);
var rtBack = Y.serialise(rtDoc);
ok('the fixed-address conversion round-trips byte for byte', rtBack === rtR.yaml,
   rtBack !== rtR.yaml ? 'first mismatch near: ' + JSON.stringify(rtBack.slice(0, 200)) : null);
ok('nothing is sealed', rtDoc.sealed.length === 0, JSON.stringify(rtDoc.sealed));
var rtForm = Y.buildForm(rtDoc);
ok('buildForm().ok is true', rtForm.ok === true);
var rtRow = rtForm.fields.filter(function (f) {
  return f.service === rtR.service && f.listKey === 'networks' && f.parts.value && f.parts.value.value === 'br0.2';
})[0];
ok('the br0.2 network row exists in the built form', !!rtRow);
ok('ipv4_address is an editable field on the network row',
   !!rtRow && rtRow.parts.ipv4_address && rtRow.parts.ipv4_address.value === '192.168.202.66');
ok('mac_address is an editable field on the network row',
   !!rtRow && rtRow.parts.mac_address && rtRow.parts.mac_address.value === '02:42:ac:11:00:02');

/* =========================================================================
 * K. Import puts the web port first (PLAN_39)
 *
 * The web-page button always opens the FIRST port in a service's list, so
 * import orders the port the template's own address named to the front —
 * the one place the unreliable [PORT:nnn] token is still read, checked
 * against the host port first, then the container port (see
 * reorderPortsForWebUI()'s own comment for the measurement behind that
 * order). A miss leaves the order alone and says so in a note, because a
 * guess there would be worse than the honest default of "the first one".
 * ========================================================================= */

console.log('\nK. Import puts the web port first');

// Two ports: host 9000/container 8080, then host 8081/container 80. The
// address names 8081 — the HOST port of the second entry — which must move
// to the front, carrying its own comment with it.
var HOST_MATCH = {
  Name: 'host-match-test', Repository: 'example/host-match-test', Network: 'bridge',
  WebUI: 'http://[IP]:[PORT:8081]',
  Config: [
    { '@attributes': { Name: 'First port', Target: '8080', Default: '9000', Mode: 'tcp',
        Description: 'First port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Second port', Target: '80', Default: '8081', Mode: 'tcp',
        Description: 'Second port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var hostMatchY = CA.convert(HOST_MATCH).yaml;
ok('a web address naming a later entry\'s HOST port moves that entry first',
   hostMatchY.indexOf('"8081:80"') >= 0 && hostMatchY.indexOf('"8081:80"') < hostMatchY.indexOf('"9000:8080"'));
ok('the moved entry keeps its own comment',
   /"8081:80"\s+# Second port/.test(hostMatchY));
ok('no reordering note is added when a match is found',
   !CA.convert(HOST_MATCH).notes.some(function (w) { return /web address names port/.test(w); }));

// Same two ports; the address instead names 80 — the CONTAINER port of the
// second entry — which must also move to the front.
var CONTAINER_MATCH = {
  Name: 'container-match-test', Repository: 'example/container-match-test', Network: 'bridge',
  WebUI: 'http://[IP]:[PORT:80]',
  Config: [
    { '@attributes': { Name: 'First port', Target: '8080', Default: '9000', Mode: 'tcp',
        Description: 'First port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Second port', Target: '80', Default: '8081', Mode: 'tcp',
        Description: 'Second port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var containerMatchY = CA.convert(CONTAINER_MATCH).yaml;
ok('a web address naming a later entry\'s CONTAINER port moves that entry first',
   containerMatchY.indexOf('"8081:80"') >= 0 && containerMatchY.indexOf('"8081:80"') < containerMatchY.indexOf('"9000:8080"'));

// Same shape again; the address names a port neither entry declares. Order
// stays as written, and a note names the port and explains the fallback.
var NO_MATCH = {
  Name: 'no-match-test', Repository: 'example/no-match-test', Network: 'bridge',
  WebUI: 'http://[IP]:[PORT:1234]',
  Config: [
    { '@attributes': { Name: 'First port', Target: '8080', Default: '9000', Mode: 'tcp',
        Description: 'First port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Second port', Target: '80', Default: '8081', Mode: 'tcp',
        Description: 'Second port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var noMatchR = CA.convert(NO_MATCH);
ok('a web address matching neither port leaves the order unchanged',
   noMatchR.yaml.indexOf('"9000:8080"') >= 0 && noMatchR.yaml.indexOf('"9000:8080"') < noMatchR.yaml.indexOf('"8081:80"'));
ok('...and a note names the port that could not be matched',
   noMatchR.notes.some(function (w) { return /web address names port 1234/.test(w); }));

// A single port: nothing to reorder, so no note either way, even though the
// address names a port that entry does not have.
var SINGLE_PORT_MISMATCH = {
  Name: 'single-port-mismatch-test', Repository: 'example/single-port-mismatch-test', Network: 'bridge',
  WebUI: 'http://[IP]:[PORT:9999]',
  Config: [
    { '@attributes': { Name: 'Only port', Target: '8080', Default: '9000', Mode: 'tcp',
        Description: 'Only port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var singlePortR = CA.convert(SINGLE_PORT_MISMATCH);
ok('a single port is left alone with no note and no crash',
   singlePortR.yaml.indexOf('"9000:8080"') >= 0 &&
   !singlePortR.notes.some(function (w) { return /web address names port/.test(w); }));

// Two ports, no WebUI at all: nothing to key the reorder on, order untouched.
var NO_WEBUI = {
  Name: 'no-webui-test', Repository: 'example/no-webui-test', Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'First port', Target: '8080', Default: '9000', Mode: 'tcp',
        Description: 'First port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Second port', Target: '80', Default: '8081', Mode: 'tcp',
        Description: 'Second port', Type: 'Port', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var noWebuiR = CA.convert(NO_WEBUI);
ok('no webui at all leaves the order unchanged',
   noWebuiR.yaml.indexOf('"9000:8080"') >= 0 && noWebuiR.yaml.indexOf('"9000:8080"') < noWebuiR.yaml.indexOf('"8081:80"'));
ok('...and adds no note', !noWebuiR.notes.some(function (w) { return /web address names port/.test(w); }));

// The reordered output still round-trips through compose-model.js unsealed —
// same pattern as section A and J-8, proving the reorder produced an
// ordinary list a hand-written file could equally contain.
var kDoc = Y.parse(hostMatchY);
var kBack = Y.serialise(kDoc);
ok('the reordered output round-trips byte for byte', kBack === hostMatchY,
   kBack !== hostMatchY ? 'first mismatch near: ' + JSON.stringify(kBack.slice(0, 200)) : null);
ok('nothing is sealed in the reordered output', kDoc.sealed.length === 0, JSON.stringify(kDoc.sealed));
var kForm = Y.buildForm(kDoc);
ok('buildForm().ok is true for the reordered output', kForm.ok === true);

/* =========================================================================
 * L. opts.origin — the provenance line
 *
 * The Apps dialog never passes opts.origin, so its output must stay
 * byte-identical to before this option existed; the importer (which runs
 * this converter over the user's own Unraid templates, not the CA catalogue)
 * passes 'template' to get a first line that is actually true.
 * ========================================================================= */

console.log('\nL. opts.origin — the imported: stamp');

var CA_FROM = '  from: community-applications';
var TEMPLATE_FROM = '  from: unraid-template';
var DATE_SHAPE = /\n {4}on: \d{4}-\d{2}-\d{2}\n/;

ok('no opts.origin stamps community-applications',
   CA.convert(EMBY).yaml.indexOf(CA_FROM) >= 0);
ok('opts.origin: "ca" stamps the same community-applications value',
   CA.convert(EMBY, { origin: 'ca' }).yaml.indexOf(CA_FROM) >= 0);
ok('opts.origin: "template" stamps unraid-template',
   CA.convert(EMBY, { origin: 'template' }).yaml.indexOf(TEMPLATE_FROM) >= 0);
ok('an unrecognised opts.origin falls back to community-applications',
   CA.convert(EMBY, { origin: 'bogus' }).yaml.indexOf(CA_FROM) >= 0);
ok('the stamp carries an on: date shaped YYYY-MM-DD',
   DATE_SHAPE.test(CA.convert(EMBY).yaml));
ok('the file no longer opens with a "Converted from" comment line',
   CA.convert(EMBY).yaml.indexOf('# Converted from') === -1);

// PLAN_105: the catalogue's icon lands on the service for either origin —
// 'ca' and 'template' both convert a single-service file, and the picture
// is the same statement wherever it came from.
ok('the icon lands on the service under opts.origin: "ca"',
   CA.convert(EMBY, { origin: 'ca' }).yaml.indexOf('      icon: https://raw.githubusercontent.com/binhex/templates') >= 0);
ok('the icon lands on the service under opts.origin: "template"',
   CA.convert(EMBY, { origin: 'template' }).yaml.indexOf('      icon: https://raw.githubusercontent.com/binhex/templates') >= 0);
ok('the file no longer carries the standing "ordinary compose file" sentences',
   CA.convert(EMBY).yaml.indexOf('This is an ordinary compose file') === -1);

// The blank line under the header used to be unconditional. With the header
// gone, a conversion that reports nothing has nothing to separate from, and
// pushing it anyway opened every clean file on an empty line.
var CLEAN = CA.convert({ Name: 'demo', Repository: 'nginx:latest', Network: 'bridge' });
ok('a conversion with nothing to report opens straight on x-unraid:',
   CLEAN.warnings.length === 0 && CLEAN.notes.length === 0 &&
   CLEAN.yaml.split('\n')[0] === 'x-unraid:');
ok('a conversion that does report something keeps its blank line above x-unraid:',
   (function () {
     var lines = CA.convert({ Name: 'demo', Network: 'bridge' }).yaml.split('\n');
     var at = lines.indexOf('x-unraid:');
     return at > 0 && lines[at - 1] === '' && lines[0].charAt(0) === '#';
   })());

/* =========================================================================
 * M. A Path setting whose Target is not a container path at all
 *
 * MQTT Explorer (a real template on the box) has Path settings whose Target
 * is a bare environment-variable-shaped word — SSL_KEY_PATH, SSL_CERT_PATH,
 * INITIAL_CONFIG — with no value set. Before this rule that produced a
 * volumes: entry naming a folder nobody meant, which Docker refuses at
 * start-up without saying why. Checked before the placeholder-value rule
 * even runs, so it never gets the chance to invent one.
 * ========================================================================= */

console.log('\nM. A Path setting with a non-path Target');

var BAD_PATH_TARGET = {
  Name: 'mqtt-explorer-test', Repository: 'example/mqtt-explorer-test', Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'SSL_KEY_PATH', Target: 'SSL_KEY_PATH', Default: '', Description: 'Path to the SSL key',
        Type: 'Path', Required: 'false', Mask: 'false' }, value: '' },
    { '@attributes': { Name: 'Config', Target: '/config', Default: '', Description: 'Configuration folder',
        Type: 'Path', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var badPathR = CA.convert(BAD_PATH_TARGET);
ok('a bare-word Target produces no volumes: entry for it',
   badPathR.yaml.indexOf('SSL_KEY_PATH:SSL_KEY_PATH') === -1);
ok('...but the sibling Path with a proper Target still gets one',
   badPathR.yaml.indexOf('/config:/config') >= 0 || badPathR.yaml.indexOf(':/config') >= 0);
ok('a warning names the bad setting and its Target',
   badPathR.warnings.some(function (w) { return /SSL_KEY_PATH/.test(w) && w.indexOf('not a folder path') >= 0; }));

// A Path with a proper absolute Target and no value must be unaffected —
// this guards against the new check over-matching a normal case.
var GOOD_PATH_TARGET = {
  Name: 'good-path-test', Repository: 'example/good-path-test', Network: 'bridge',
  Config: [
    { '@attributes': { Name: 'Config', Target: '/config', Default: '', Description: '',
        Type: 'Path', Required: 'false', Mask: 'false' }, value: '' }
  ]
};
var goodPathR = CA.convert(GOOD_PATH_TARGET);
ok('a proper absolute Target still gets its placeholder volume',
   goodPathR.yaml.indexOf('/mnt/user/appdata/good-path-test/config:/config') >= 0);
ok('...and produces no warning',
   goodPathR.warnings.length === 0);

/* =========================================================================
 * N. container_name carries Name verbatim
 *
 * Docker names the running container from Name exactly — capitals and all —
 * so the importer's handover (replace the old container by name) only finds
 * something to replace if container_name matches it byte for byte. Measured
 * against six real Unraid templates: five of six have a Name Docker itself
 * would accept unchanged, so lowercasing it was always a divergence from
 * what Unraid actually did.
 * ========================================================================= */

console.log('\nN. container_name carries Name verbatim');

['Vert', 'Reubah', 'QRding', 'Excalidraw', 'StirlingPDF', 'it-tools'].forEach(function (nm) {
  var y = CA.convert({ Name: nm, Repository: 'example/' + nm.toLowerCase(), Network: 'bridge' }).yaml;
  ok('container_name for Name "' + nm + '" is written verbatim',
     y.indexOf('container_name: ' + nm + '\n') >= 0, y.match(/container_name:.*/)[0]);
});

var spaceNameR = CA.convert({ Name: 'My Cool App', Repository: 'example/my-cool-app', Network: 'bridge' });
ok('a Name with a space is not a legal Docker container name, so it falls back to the normalised form',
   spaceNameR.yaml.indexOf('container_name: my-cool-app\n') >= 0);
ok('the service key uses the same normalised, lowercase form',
   spaceNameR.yaml.indexOf('  my-cool-app:\n') >= 0);

var lowerNameR = CA.convert({ Name: 'already-lowercase', Repository: 'example/already-lowercase', Network: 'bridge' });
ok('a Name already lowercase is unchanged', lowerNameR.yaml.indexOf('container_name: already-lowercase\n') >= 0);

var capsNameR = CA.convert({ Name: 'Excalidraw', Repository: 'example/excalidraw', Network: 'bridge' });
ok('a Name with capitals keeps them in container_name', capsNameR.yaml.indexOf('container_name: Excalidraw\n') >= 0);
ok('...while the service key stays lowercase', capsNameR.yaml.indexOf('  excalidraw:\n') >= 0);
ok('...and the stack name (used for the appdata placeholder etc.) stays lowercase too', capsNameR.name === 'excalidraw');

/* ---- summary ------------------------------------------------------------ */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
