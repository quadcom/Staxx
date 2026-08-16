/* Stack Manager — Community Applications template converter.
 * Copyright 2026, Stack Manager contributors. GPL-2.0.
 *
 * Turns one Community Applications feed entry (the JSON shape CA already
 * fetches for its own gallery) into compose YAML text. Pure string in, string
 * out — no DOM, so this runs equally well in the browser and under Node for
 * tests/ca_convert.js.
 *
 * The output must survive compose-model.js's parse()/serialise()/buildForm()
 * unchanged and unsealed, so this file sticks to the safe subset that parser
 * understands: block mappings, two-space indent, no anchors, aliases, merge
 * keys or flow collections. See tests/ca_convert.js for the round-trip proof.
 *
 * ExtraParams (a `docker run` argument string) is the one lossy edge: only a
 * known set of flags has a Compose equivalent, and every flag this cannot map
 * is reported in `warnings` rather than silently dropped — a setting that
 * quietly stopped applying is worse than an ugly one.
 */

(function () {
  'use strict';

  /* =====================================================================
   * Name normalisation — must satisfy stackman_valid_name() in Stacks.php:
   * lowercase, [a-z0-9._-] only, starts alphanumeric, <=63 chars, no "..".
   * ===================================================================== */

  function normaliseName(raw) {
    var s = String(raw == null ? '' : raw).toLowerCase();
    s = s.replace(/[^a-z0-9._-]+/g, '-');
    s = s.replace(/^[-.]+/, '').replace(/[-.]+$/, '');
    // ".." is refused by stackman_valid_name() even though every character in
    // it is individually allowed, so it needs its own collapse.
    while (s.indexOf('..') >= 0) s = s.replace(/\.\.+/g, '.');
    if (s === '') return 'imported-app';
    if (!/^[a-z0-9]/.test(s)) s = 'app-' + s;
    if (s.length > 63) s = s.slice(0, 63);
    s = s.replace(/[-.]+$/, '');
    return s === '' ? 'imported-app' : s;
  }

  /* =====================================================================
   * Small YAML-safety helpers
   *
   * This is not a general YAML emitter — it only ever writes the shapes
   * compose-model.js's parser already understands, and only ever writes
   * values this module built itself, so "safe" here just means "won't be
   * misread by that parser or by a real YAML 1.1 loader downstream".
   * ===================================================================== */

  function dq(v) {
    return '"' + String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
  }

  var UNSAFE_LEAD = /^[\s\-?:,\[\]{}#&*!|>'"%@`]/;
  var YAML_KEYWORD = /^(true|false|yes|no|on|off|null|~)$/i;
  var LOOKS_NUMERIC = /^[-+]?[0-9]+(\.[0-9]+)?$/;

  function isSafeBare(s) {
    s = String(s == null ? '' : s);
    if (s === '') return false;
    if (/^\s|\s$/.test(s)) return false;
    if (UNSAFE_LEAD.test(s.charAt(0))) return false;
    if (s.indexOf(': ') >= 0 || /:$/.test(s)) return false;
    if (s.indexOf(' #') >= 0) return false;
    if (YAML_KEYWORD.test(s)) return false;
    if (LOOKS_NUMERIC.test(s)) return false;
    return true;
  }

  function scalarOut(v) {
    return isSafeBare(v) ? v : dq(v);
  }

  // A mapping key is stricter than a value: compose-model.js's own KEY_RE
  // takes a bare key up to the FIRST colon, full stop, so a key containing
  // one at all (a real feed quirk: a Target of literally "SITENAME:") has to
  // be quoted or it silently produces a second, bogus colon.
  function keyOut(k) {
    k = String(k == null ? '' : k);
    if (k !== '' && !/[:#"'\s]/.test(k) && !UNSAFE_LEAD.test(k.charAt(0))) return k;
    return dq(k);
  }

  function repeat(ch, n) {
    var s = '';
    while (n-- > 0) s += ch;
    return s;
  }

  // "align the # to (longest content line in that block + 2 spaces), capped
  // at column 60; a line longer than that column just gets two spaces before
  // its #". Lines with no comment at all are left exactly as they are and do
  // not count towards the column, since there is nothing there to align.
  function emitAlignedBlock(items) {
    var maxLen = 0;
    var i;
    for (i = 0; i < items.length; i++) {
      if (items[i].comment) maxLen = Math.max(maxLen, items[i].content.length);
    }
    var col = Math.min(maxLen + 2, 60);
    var out = [];
    for (i = 0; i < items.length; i++) {
      var it = items[i];
      if (!it.comment) { out.push(it.content); continue; }
      var pad = col - it.content.length;
      if (pad < 2) pad = 2;
      out.push(it.content + repeat(' ', pad) + '# ' + it.comment);
    }
    return out;
  }

  /* =====================================================================
   * Description / Overview text handling
   * ===================================================================== */

  var NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

  function decodeEntitiesAndBr(s) {
    s = String(s == null ? '' : s);
    s = s.replace(/<br\s*\/?\s*>/gi, ' ');
    s = s.replace(/\r\n|\r|\n/g, ' ');
    s = s.replace(/&(#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z]+);/g, function (m, ent) {
      if (ent.charAt(0) === '#') {
        var code = (ent.charAt(1) === 'x' || ent.charAt(1) === 'X') ? parseInt(ent.slice(2), 16) : parseInt(ent.slice(1), 10);
        return isNaN(code) ? m : String.fromCharCode(code);
      }
      return Object.prototype.hasOwnProperty.call(NAMED_ENTITIES, ent) ? NAMED_ENTITIES[ent] : m;
    });
    return s;
  }

  // "about 160 characters at a word boundary with a trailing …".
  function truncateWords(s, limit) {
    if (s.length <= limit) return s;
    var cut = s.slice(0, limit);
    var sp = cut.lastIndexOf(' ');
    if (sp > 40) cut = cut.slice(0, sp);
    return cut.replace(/[\s.,;:]+$/, '') + '…';
  }

  // The trailing comment for one Config setting: flattened description, then
  // the -!R / -!S markers at the very end, in that order, only when present.
  function buildComment(a) {
    var desc = decodeEntitiesAndBr(a.Description || '');
    desc = desc.replace(/\s+/g, ' ').trim();
    desc = truncateWords(desc, 160);
    if (a.Required === 'true') desc += (desc ? ' ' : '') + '-!R';
    if (a.Mask === 'true') desc += (desc ? ' ' : '') + '-!S';
    return desc;
  }

  function cleanOverview(s) {
    s = decodeEntitiesAndBr(s);
    return s.replace(/\s+/g, ' ').trim();
  }

  function wrapText(s, width) {
    var words = s.split(' ');
    var lines = [], cur = '';
    for (var i = 0; i < words.length; i++) {
      var w = words[i];
      if (cur === '') cur = w;
      else if (cur.length + 1 + w.length <= width) cur += ' ' + w;
      else { lines.push(cur); cur = w; }
    }
    if (cur) lines.push(cur);
    return lines;
  }

  // The banner of warnings is gone the moment the editor closes, which is
  // exactly when the information is most useful — so it is written into the
  // file too, as a "- " bulleted, word-wrapped comment block. Continuation
  // lines get the same four-column indent as the bullet ("# - " and "#   "
  // are both four characters) so the list still reads as a list once every
  // line is wrapped to a sane width. A '#' at column 0 is always a comment
  // to compose-model.js's parser regardless of what follows it, so any
  // warning text is safe here verbatim.
  function warningCommentLines(warnings) {
    var out = [];
    warnings.forEach(function (w) {
      var text = String(w == null ? '' : w).replace(/\s+/g, ' ').trim();
      if (!text) return;
      var wrapped = wrapText(text, 78);
      for (var i = 0; i < wrapped.length; i++) {
        out.push('# ' + (i === 0 ? '- ' : '  ') + wrapped[i]);
      }
    });
    return out;
  }

  /* =====================================================================
   * Category / Network
   * ===================================================================== */

  var CATEGORY_HEADS = ['AI', 'Backup', 'Cloud', 'Crypto', 'Downloaders', 'Drivers',
    'GameServers', 'HomeAutomation', 'MediaApp', 'MediaServer', 'Network', 'Plugins',
    'Productivity', 'Security', 'Tools', 'Other'];

  function normaliseCategory(app) {
    var raw;
    if (Array.isArray(app.CategoryList) && app.CategoryList.length) raw = app.CategoryList[0];
    else if (app.Category) raw = app.Category;
    else return '';
    var idx = raw.indexOf('-');
    if (idx < 0) return raw;
    var head = raw.slice(0, idx), rest = raw.slice(idx + 1);
    return CATEGORY_HEADS.indexOf(head) >= 0 ? head + ':' + rest : raw;
  }

  // bridge/empty -> Compose's own default network, nothing to write.
  // host/none -> network_mode. Anything else is a named network that must
  // already exist on the server, so a warning rides along with it.
  function networkInfo(app, warnings) {
    var raw = String(app.Network == null ? '' : app.Network).trim();
    if (raw === '' || raw.toLowerCase() === 'bridge') return { mode: null, network: null };
    var lower = raw.toLowerCase();
    if (lower === 'host' || lower === 'none') return { mode: lower, network: null };
    var name = raw.replace(/^custom:\s*/i, '').trim();
    warnings.push('This container is set to use the network "' + name + '". That network ' +
      'must already exist on this server or Compose will refuse to start the stack.');
    return { mode: null, network: name };
  }

  function pathModeSuffix(mode) {
    var m = String(mode == null ? '' : mode).trim();
    if (m === '' || m === 'rw') return '';
    if (m === 'ro' || m === 'r') return ':ro';
    return ':' + m;                                     // e.g. rw,slave — passed through verbatim
  }

  /* =====================================================================
   * ExtraParams — a `docker run` argument string
   * ===================================================================== */

  // A "could not be translated" warning on its own leaves nowhere to go.
  // Where a compose equivalent exists, name it — but only the ones this file
  // is actually sure of; anything not listed here keeps the plain wording,
  // because a guessed-at hint is worse than no hint at all. Never used to
  // drive a conversion, only to annotate the warning about one that did not
  // happen — see the module comment for why these flags are not translated.
  var FLAG_HINTS = {
    '--gpus': 'The compose equivalent is deploy.resources.reservations.devices, with capabilities: [gpu].',
    '--health-cmd': 'The compose equivalent is a healthcheck: block, using its test key.',
    '--health-interval': 'The compose equivalent is a healthcheck: block, using its interval key.',
    '--health-timeout': 'The compose equivalent is a healthcheck: block, using its timeout key.',
    '--health-retries': 'The compose equivalent is a healthcheck: block, using its retries key.',
    '--health-start-period': 'The compose equivalent is a healthcheck: block, using its start_period key.',
    '--no-healthcheck': 'The compose equivalent is healthcheck: disable: true.',
    '--memory': 'The compose equivalent is mem_limit.',
    '--memory-swap': 'The compose equivalent is memswap_limit.',
    '--cpus': 'The compose equivalent is cpus.',
    '--cpu-shares': 'The compose equivalent is cpu_shares.',
    '--ulimit': 'The compose equivalent is ulimits.',
    '--pids-limit': 'The compose equivalent is pids_limit.',
    '--device-cgroup-rule': 'The compose equivalent is device_cgroup_rules.',
    '--expose': 'The compose equivalent is expose.',
    '--env-file': 'The compose equivalent is env_file.',
    '-v': 'The compose equivalent is a volumes: entry — add it in the Volumes section of the form.',
    '--volume': 'The compose equivalent is a volumes: entry — add it in the Volumes section of the form.',
    '-p': 'The compose equivalent is a ports: entry — add it in the Ports section of the form.',
    '--publish': 'The compose equivalent is a ports: entry — add it in the Ports section of the form.',
    '--mount': 'The compose equivalent is a volumes: entry, using the long syntax.',
    '--net': 'The compose equivalent is network_mode, or networks for a named network.',
    '--link': '--link is obsolete; put both services in the same stack and use the service name instead.'
  };

  // Quote-aware word splitting. A quote can open and close mid-token, joining
  // whatever is inside across a space — "ES_JAVA_OPTS"="-Xms512m -Xmx512m" is
  // one token, not four, which is exactly the case a naive .split(' ') gets
  // wrong on real feed data (see elasticsearch's ExtraParams).
  function tokenizeExtraParams(str) {
    var tokens = [], cur = '', have = false, quote = null;
    for (var i = 0; i < str.length; i++) {
      var ch = str.charAt(i);
      if (quote) {
        if (ch === quote) { quote = null; }
        else if (quote === '"' && ch === '\\' && i + 1 < str.length &&
                 (str.charAt(i + 1) === '"' || str.charAt(i + 1) === '\\')) {
          cur += str.charAt(++i);
        } else {
          cur += ch;
        }
        have = true;
        continue;
      }
      if (ch === '"' || ch === "'") { quote = ch; have = true; continue; }
      if (ch === ' ' || ch === '\t') {
        if (have) { tokens.push(cur); cur = ''; have = false; }
        continue;
      }
      cur += ch;
      have = true;
    }
    if (have) tokens.push(cur);
    return tokens;
  }

  function parseExtraParams(str) {
    var out = {
      restart: '', capAdd: [], capDrop: [], devices: [], shmSize: '',
      hostname: '', runtime: '', user: '', securityOpt: [], sysctls: [],
      dns: [], groupAdd: [], pid: '', ipc: '', readOnly: false, init: false,
      tmpfs: [], loggingDriver: '', loggingOptions: [], extraHosts: [],
      entrypoint: '', stopSignal: '', stopGracePeriod: '', cgroup: '',
      macAddress: '', platform: '', workingDir: '', labels: [], environment: [],
      privileged: false, warnings: []
    };
    if (!str) return out;

    var SCALARS = {
      '--shm-size': 'shmSize', '--hostname': 'hostname', '-h': 'hostname',
      '--runtime': 'runtime', '--user': 'user', '-u': 'user',
      '--pid': 'pid', '--ipc': 'ipc', '--stop-signal': 'stopSignal',
      '--cgroupns': 'cgroup', '--mac-address': 'macAddress', '--platform': 'platform',
      '--workdir': 'workingDir', '-w': 'workingDir', '--entrypoint': 'entrypoint',
      '--log-driver': 'loggingDriver'
    };
    var LISTS = {
      '--cap-add': 'capAdd', '--cap-drop': 'capDrop', '--device': 'devices',
      '--security-opt': 'securityOpt', '--sysctl': 'sysctls', '--dns': 'dns',
      '--group-add': 'groupAdd', '--tmpfs': 'tmpfs', '--log-opt': 'loggingOptions',
      '--add-host': 'extraHosts'
    };
    var BOOLS = { '--read-only': 'readOnly', '--init': 'init', '--privileged': 'privileged' };
    var KV = { '--label': 'labels', '--env': 'environment', '-e': 'environment' };
    var NO_VALUE_DROP = { '-d': 1, '--detach': 1, '-it': 1, '-i': 1, '-t': 1,
                           '--tty': 1, '--interactive': 1, '--rm': 1 };

    var tokens = tokenizeExtraParams(str);
    var dropped = [];
    var i = 0;
    while (i < tokens.length) {
      var tok = tokens[i]; i++;
      if (tok.charAt(0) !== '-') {
        out.warnings.push('The extra Docker option "' + tok + '" had no flag in front of it and was not applied.');
        continue;
      }
      var eq = tok.indexOf('=');
      var flag = eq >= 0 ? tok.slice(0, eq) : tok;
      var inline = eq >= 0 ? tok.slice(eq + 1) : null;

      if (NO_VALUE_DROP.hasOwnProperty(flag)) { dropped.push(flag); continue; }
      if (flag === '--name') {
        dropped.push(flag);
        if (inline === null && i < tokens.length) i++;   // still consume its value
        continue;
      }
      if (flag === '--restart') {
        var rv = inline;
        if (rv === null && i < tokens.length && tokens[i].charAt(0) !== '-') rv = tokens[i++];
        out.restart = rv ? rv : 'unless-stopped';
        continue;
      }
      if (flag === '--network-alias') {
        if (inline === null && i < tokens.length) i++;
        out.warnings.push('--network-alias was not applied: it needs a named network to attach ' +
          'to, which this conversion has no way to set up on its own. The compose ' +
          'equivalent is networks.<name>.aliases.');
        continue;
      }
      if (flag === '--stop-timeout') {
        var stv = inline !== null ? inline : (i < tokens.length ? tokens[i++] : '');
        out.stopGracePeriod = stv + 's';
        continue;
      }
      if (BOOLS.hasOwnProperty(flag)) { out[BOOLS[flag]] = true; continue; }
      if (SCALARS.hasOwnProperty(flag)) {
        out[SCALARS[flag]] = inline !== null ? inline : (i < tokens.length ? tokens[i++] : '');
        continue;
      }
      if (LISTS.hasOwnProperty(flag)) {
        out[LISTS[flag]].push(inline !== null ? inline : (i < tokens.length ? tokens[i++] : ''));
        continue;
      }
      if (KV.hasOwnProperty(flag)) {
        var kv = inline !== null ? inline : (i < tokens.length ? tokens[i++] : '');
        var eq2 = kv.indexOf('=');
        if (eq2 >= 0) out[KV[flag]].push({ key: kv.slice(0, eq2), value: kv.slice(eq2 + 1) });
        else out[KV[flag]].push({ key: kv, value: '' });
        continue;
      }

      // Unrecognised — reported, never silently dropped. Best-effort guess at
      // whether the next token is this flag's value (most of the ones this
      // hits, --gpus, --memory, -v, -p… always take one in space form).
      var val2 = inline;
      if (val2 === null && i < tokens.length && tokens[i].charAt(0) !== '-') val2 = tokens[i++];
      var shown = val2 !== null ? (flag + '=' + val2) : flag;
      var hint = FLAG_HINTS.hasOwnProperty(flag) ? ' ' + FLAG_HINTS[flag] : '';
      out.warnings.push('The Docker option "' + shown + '" could not be translated to a Compose setting and was not applied. Check whether it is still needed.' + hint);
    }

    if (dropped.length) {
      out.warnings.push('Dropped these `docker run` options, which Compose already provides on its own: ' + dropped.join(', ') + '.');
    }
    return out;
  }

  /* =====================================================================
   * Config array — Port / Path / Device / Variable / Label
   * ===================================================================== */

  function resolveValue(entryValue, def) {
    if (entryValue !== undefined && entryValue !== null && entryValue !== '') return entryValue;
    if (def === undefined || def === null || def === '') return '';
    // "a|b|c" is Unraid's choice-list convention — take the first when there
    // is no value of the setting's own.
    return def.indexOf('|') >= 0 ? def.split('|')[0] : def;
  }

  function processConfig(configArr, name, warnings) {
    var ports = [], volumes = [], devices = [], environment = [], labels = [];
    if (!Array.isArray(configArr)) configArr = [];

    configArr.forEach(function (c) {
      var a = (c && c['@attributes']) || {};
      var type = a.Type;
      var label = a.Name || a.Target || '(unnamed setting)';

      if (!type) {
        warnings.push('The setting "' + label + '" has no declared type and was skipped.');
        return;
      }
      var target = a.Target || '';
      if (type !== 'Device' && target === '') {
        warnings.push('The setting "' + label + '" has nothing to key it on and was skipped.');
        return;
      }

      var val = resolveValue(c.value, a.Default);
      var comment = buildComment(a);

      if (type === 'Port') {
        if (val === '') {
          val = target;
          warnings.push('The port "' + label + '" had no value; used the container port ' + target + ' as the host port too.');
        }
        var udp = String(a.Mode || '').toLowerCase() === 'udp';
        ports.push({ content: '      - ' + dq(val + ':' + target + (udp ? '/udp' : '')), comment: comment });
      } else if (type === 'Path') {
        if (val === '') {
          val = '/mnt/user/appdata/' + name + target;
          warnings.push('The path "' + label + '" had no value; used ' + val + ' as a placeholder — check it before starting the stack.');
        }
        volumes.push({ content: '      - ' + scalarOut(val + ':' + target + pathModeSuffix(a.Mode)), comment: comment });
      } else if (type === 'Device') {
        if (val === '') {
          warnings.push('The device "' + label + '" had no value and was skipped — there is no safe default for a device node.');
          return;
        }
        devices.push({ content: '      - ' + scalarOut(val), comment: comment });
      } else if (type === 'Variable') {
        // Emitted even when empty: the form shows it with its description,
        // which is the whole point of importing the metadata.
        environment.push({ content: '      ' + keyOut(target) + ': ' + dq(val), comment: comment });
      } else if (type === 'Label') {
        labels.push({ content: '      ' + keyOut(target) + ': ' + dq(val), comment: comment });
      } else {
        warnings.push('The setting "' + label + '" has the unrecognised type "' + type + '" and was skipped.');
      }
    });

    return { ports: ports, volumes: volumes, devices: devices, environment: environment, labels: labels };
  }

  /* =====================================================================
   * Assembly
   * ===================================================================== */

  function pushListBlock(svc, key, arr) {
    if (!arr.length) return;
    svc.push('    ' + key + ':');
    for (var i = 0; i < arr.length; i++) svc.push('      - ' + scalarOut(arr[i]));
  }

  function convert(app) {
    app = app || {};
    var warnings = [];
    var name = normaliseName(app.Name);
    var service = name;

    var net = networkInfo(app, warnings);
    var cfg = processConfig(app.Config, name, warnings);
    var extra = parseExtraParams(app.ExtraParams || '');
    warnings = warnings.concat(extra.warnings);

    var privileged = app.Privileged === 'true' || extra.privileged;

    var devices = cfg.devices.concat(extra.devices.map(function (v) {
      return { content: '      - ' + scalarOut(v), comment: '' };
    }));
    var environment = cfg.environment.concat(extra.environment.map(function (kv) {
      return { content: '      ' + keyOut(kv.key) + ': ' + dq(kv.value), comment: '' };
    }));
    var labels = cfg.labels.concat(extra.labels.map(function (kv) {
      return { content: '      ' + keyOut(kv.key) + ': ' + dq(kv.value), comment: '' };
    }));

    /* ---- the service body -------------------------------------------- */

    var svc = [];
    if (app.Repository) {
      svc.push('    image: ' + app.Repository);
    } else {
      warnings.push('This template has no image name. Check "image:" before starting the stack.');
      svc.push('    image: ' + name);
    }
    svc.push('    container_name: ' + name);
    // Always written, defaulting to unless-stopped when the template said
    // nothing. Compose's own default is `no`, which means the container does
    // not come back after a reboot — and a container from Community
    // Applications that silently stops surviving restarts is not the same
    // container the template described.
    svc.push('    restart: ' + scalarOut(extra.restart || 'unless-stopped'));

    if (privileged) svc.push('    privileged: true');
    if (extra.hostname) svc.push('    hostname: ' + scalarOut(extra.hostname));
    if (extra.workingDir) svc.push('    working_dir: ' + scalarOut(extra.workingDir));
    if (extra.user) svc.push('    user: ' + scalarOut(extra.user));
    if (extra.entrypoint) svc.push('    entrypoint: ' + scalarOut(extra.entrypoint));
    if (app.PostArgs) svc.push('    command: ' + scalarOut(app.PostArgs));
    if (extra.runtime) svc.push('    runtime: ' + scalarOut(extra.runtime));
    if (extra.platform) svc.push('    platform: ' + scalarOut(extra.platform));
    if (extra.readOnly) svc.push('    read_only: true');
    if (extra.init) svc.push('    init: true');
    if (extra.pid) svc.push('    pid: ' + scalarOut(extra.pid));
    if (extra.ipc) svc.push('    ipc: ' + scalarOut(extra.ipc));
    if (extra.cgroup) svc.push('    cgroup: ' + scalarOut(extra.cgroup));
    if (extra.macAddress) svc.push('    mac_address: ' + scalarOut(extra.macAddress));
    if (extra.stopSignal) svc.push('    stop_signal: ' + scalarOut(extra.stopSignal));
    if (extra.stopGracePeriod) svc.push('    stop_grace_period: ' + scalarOut(extra.stopGracePeriod));
    if (extra.shmSize) svc.push('    shm_size: ' + scalarOut(extra.shmSize));

    if (net.mode) {
      svc.push('    network_mode: ' + net.mode);
    } else if (net.network) {
      svc.push('    networks:');
      svc.push('      - ' + scalarOut(net.network));
    }

    if (cfg.ports.length) { svc.push('    ports:'); emitAlignedBlock(cfg.ports).forEach(function (l) { svc.push(l); }); }
    if (cfg.volumes.length) { svc.push('    volumes:'); emitAlignedBlock(cfg.volumes).forEach(function (l) { svc.push(l); }); }
    if (devices.length) { svc.push('    devices:'); emitAlignedBlock(devices).forEach(function (l) { svc.push(l); }); }
    if (environment.length) { svc.push('    environment:'); emitAlignedBlock(environment).forEach(function (l) { svc.push(l); }); }
    if (labels.length) { svc.push('    labels:'); emitAlignedBlock(labels).forEach(function (l) { svc.push(l); }); }

    pushListBlock(svc, 'cap_add', extra.capAdd);
    pushListBlock(svc, 'cap_drop', extra.capDrop);
    pushListBlock(svc, 'security_opt', extra.securityOpt);
    pushListBlock(svc, 'sysctls', extra.sysctls);
    pushListBlock(svc, 'dns', extra.dns);
    pushListBlock(svc, 'group_add', extra.groupAdd);
    pushListBlock(svc, 'tmpfs', extra.tmpfs);
    pushListBlock(svc, 'extra_hosts', extra.extraHosts);
    if (extra.loggingDriver || extra.loggingOptions.length) {
      svc.push('    logging:');
      if (extra.loggingDriver) svc.push('      driver: ' + scalarOut(extra.loggingDriver));
      if (extra.loggingOptions.length) {
        svc.push('      options:');
        extra.loggingOptions.forEach(function (opt) {
          var oeq = opt.indexOf('=');
          var optKey = oeq >= 0 ? opt.slice(0, oeq) : opt;
          var optVal = oeq >= 0 ? opt.slice(oeq + 1) : '';
          svc.push('        ' + keyOut(optKey) + ': ' + dq(optVal));
        });
      }
    }

    if (app.WebUI) {
      svc.push('    x-unraid:');
      svc.push('      webui: ' + dq(app.WebUI));
    }

    /* ---- stack-level x-unraid ------------------------------------------ */

    var stackMeta = ['  version: 1'];
    if (app.Icon) stackMeta.push('  icon: ' + scalarOut(app.Icon));
    var category = normaliseCategory(app);
    if (category) stackMeta.push('  category: ' + scalarOut(category));
    if (app.Project) stackMeta.push('  project: ' + scalarOut(app.Project));
    if (app.Support) stackMeta.push('  support: ' + scalarOut(app.Support));
    if (app.ReadMe) stackMeta.push('  readme: ' + scalarOut(app.ReadMe));
    var author = app.Author || app.Repo || '';
    if (author) stackMeta.push('  author: ' + scalarOut(author));
    var overview = app.Overview ? cleanOverview(app.Overview) : '';
    if (overview) {
      stackMeta.push('  overview: |');
      wrapText(overview, 78).forEach(function (l) { stackMeta.push('    ' + l); });
    }

    /* ---- whole file ------------------------------------------------------ */

    var lines = [];
    lines.push('# Converted from the Community Applications template for ' + (app.Name || name) + '.');
    lines.push('#');
    lines.push('# This is an ordinary compose file — delete every x-unraid block below and');
    lines.push('# it still runs with a plain `docker compose up`. Check the ports and paths');
    lines.push('# before starting it; some may have been filled in with placeholder defaults.');
    if (warnings.length) {
      lines.push('#');
      lines.push('# Could not be translated automatically:');
      lines = lines.concat(warningCommentLines(warnings));
    }
    lines.push('');
    lines.push('x-unraid:');
    lines = lines.concat(stackMeta);
    lines.push('');
    lines.push('services:');
    lines.push('  ' + service + ':');
    lines = lines.concat(svc);

    if (net.network) {
      lines.push('');
      lines.push('networks:');
      lines.push('  ' + keyOut(net.network) + ':');
      lines.push('    external: true');
    }

    var yaml = lines.join('\n') + '\n';

    return { name: name, service: service, yaml: yaml, warnings: warnings };
  }

  /* =====================================================================
   * Export — dual target exactly like compose-model.js
   * ===================================================================== */

  var API = {
    convert: convert,
    normaliseName: normaliseName,
    parseExtraParams: parseExtraParams
  };

  if (typeof window !== 'undefined') window.StackmanCA = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
