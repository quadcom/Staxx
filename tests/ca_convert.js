/* Stack Manager — tests for the Community Applications template converter.
 * Copyright 2026, Stack Manager contributors. GPL-2.0.
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

var CA = require('../src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/ca-convert.js');
var Y  = require('../src/stack.manager/usr/local/emhttp/plugins/stack.manager/javascript/compose-model.js');

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
  ok(pair[0] + ': name matches stackman_valid_name()', VALID_NAME.test(r.name), r.name);
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
ok('container_name: equals the name', embyY.indexOf('container_name: binhex-emby') >= 0);
ok('bridge network emits no network_mode/networks', embyY.indexOf('network_mode') === -1 && embyY.indexOf('networks:') === -1);
ok('ExtraParams --restart=unless-stopped becomes restart: unless-stopped', embyY.indexOf('restart: unless-stopped') >= 0);
ok('the empty-valued Port falls back to Default 8096', embyY.indexOf('"8096:8096"') >= 0);
ok('the empty-valued /config Path falls back to its Default', embyY.indexOf('/mnt/user/appdata/emby:/config') >= 0);
ok('a Path Mode of plain rw gets no :suffix', embyY.indexOf('/mnt/user:/media') >= 0 && embyY.indexOf('/mnt/user:/media:rw') === -1);
ok('the yes|no choice-list default picks the first option', embyY.indexOf('ENABLE_HEALTHCHECK: "yes"') >= 0);
ok('an empty Variable with an empty Default is still emitted, empty', embyY.indexOf('HEALTHCHECK_COMMAND: ""') >= 0);
ok('Icon becomes stack x-unraid.icon', embyY.indexOf('icon: https://raw.githubusercontent.com/binhex/templates') >= 0);
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
ok('bridge network on linkstack emits nothing network-related', linkY.indexOf('network_mode') === -1 && linkY.indexOf('networks:') === -1);

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
ok('omitting opts falls back to /mnt/user/appdata/',
   pathOnlyR.yaml.indexOf('/mnt/user/appdata/path-only-test/config') >= 0);

var customRootR = CA.convert(PATH_ONLY_TEST, { appdataRoot: '/mnt/cache/appdata/' });
ok('convert(app, { appdataRoot }) uses it for the placeholder path',
   customRootR.yaml.indexOf('/mnt/cache/appdata/path-only-test/config') >= 0);

var noSlashRootR = CA.convert(PATH_ONLY_TEST, { appdataRoot: '/mnt/cache/appdata' });
ok('a root with no trailing slash is normalised to exactly one',
   noSlashRootR.yaml.indexOf('/mnt/cache/appdata/path-only-test/config') >= 0);

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

/* ---- summary ------------------------------------------------------------ */

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
