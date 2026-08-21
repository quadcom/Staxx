/* StaXX — Community Applications template converter.
 * Copyright 2026, StaXX contributors. GPL-2.0.
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
   * Name normalisation — must satisfy staxx_valid_name() in Stacks.php:
   * lowercase, [a-z0-9._-] only, starts alphanumeric, <=63 chars, no "..".
   * ===================================================================== */

  function normaliseName(raw) {
    var s = String(raw == null ? '' : raw).toLowerCase();
    s = s.replace(/[^a-z0-9._-]+/g, '-');
    s = s.replace(/^[-.]+/, '').replace(/[-.]+$/, '');
    // ".." is refused by staxx_valid_name() even though every character in
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
    // Collapsed before escaping: a real newline inside a double-quoted
    // scalar is legal YAML but breaks the value across two lines in the
    // file, which every other multi-line value here (buildComment,
    // cleanOverview) already avoids by flattening to one line first.
    return '"' + String(v == null ? '' : v).replace(/[\r\n]+/g, ' ')
      .replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
  }

  var UNSAFE_LEAD = /^[\s\-?:,\[\]{}#&*!|>'"%@`]/;
  var YAML_KEYWORD = /^(true|false|yes|no|on|off|null|~)$/i;
  var LOOKS_NUMERIC = /^[-+]?[0-9]+(\.[0-9]+)?$/;

  function isSafeBare(s) {
    s = String(s == null ? '' : s);
    if (s === '') return false;
    if (/^\s|\s$/.test(s)) return false;
    // A bare scalar has no quotes to carry a newline inside — an internal
    // one would otherwise start a new (unindented, invalid) line in the
    // file, so any newline routes the value through dq() instead.
    if (/[\r\n]/.test(s)) return false;
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
    // A key named literally "yes"/"no"/"on" etc. is harmless bare under
    // compose-model.js's own parser, which never folds it to a boolean, but
    // a real YAML 1.1 loader downstream would — so it is quoted the same as
    // any other keyword, matching scalarOut()'s own rule for values.
    if (k !== '' && !YAML_KEYWORD.test(k) && !/[:#"'\s]/.test(k) && !UNSAFE_LEAD.test(k.charAt(0))) return k;
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

  // Is this field a value we can write, rather than merely present?
  //
  // A catalogue entry arrives as JSON and its unset fields are absent. An
  // Unraid TEMPLATE arrives as XML, and an empty element (`<PostArgs/>`)
  // survives the trip through PHP as an empty ARRAY — which is truthy in
  // JavaScript. A plain `if (app.PostArgs)` therefore fires for a template
  // that sets no arguments at all and writes `command: ""`, overriding
  // whatever the image itself was going to run. Measured: it would have hit
  // 83 of 85 real templates.
  //
  // The importer coerces these away before they reach here, but this file has
  // its own tests and more than one caller, so it refuses the shape itself
  // rather than trusting every one of them. An object is rejected for the
  // same reason: neither an array nor a map is a scalar we can write.
  function scalarPresent(v) {
    if (typeof v === 'string') return v !== '';
    return v !== null && v !== undefined && typeof v !== 'object';
  }

  function normaliseCategory(app) {
    var raw;
    if (Array.isArray(app.CategoryList) && app.CategoryList.length) raw = app.CategoryList[0];
    else if (scalarPresent(app.Category)) raw = String(app.Category);
    else return '';
    var idx = raw.indexOf('-');
    if (idx < 0) return raw;
    var head = raw.slice(0, idx), rest = raw.slice(idx + 1);
    return CATEGORY_HEADS.indexOf(head) >= 0 ? head + ':' + rest : raw;
  }

  var IPV4_RE = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
  var MAC_RE = /^[0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5}$/;

  function isValidIPv4(s) {
    var m = IPV4_RE.exec(s);
    if (!m) return false;
    for (var i = 1; i <= 4; i++) { if (+m[i] > 255) return false; }
    return true;
  }

  // bridge/empty -> Compose's own default network, nothing to write.
  // host/none -> network_mode. Anything else is a named network that must
  // already exist on the server, so a note rides along with it.
  //
  // A fixed IP/MAC (Unraid's MyIP/MyMAC) rides along when there is somewhere
  // for it to live. `extraMac` is ExtraParams' --mac-address, read via
  // scalarPresent() by the caller before it gets here — MyIP/MyMAC need the
  // same care, since an empty `<MyIP/>` element survives PHP's XML parse as
  // an empty array, which is truthy, so a plain `if (app.MyIP)` would fire on
  // a template that set no address at all.
  function networkInfo(app, notes, warnings, extraMac) {
    var raw = String(app.Network == null ? '' : app.Network).trim();
    var lower = raw.toLowerCase();
    var mode = null, network = null;
    if (raw !== '' && lower !== 'bridge') {
      if (lower === 'host' || lower === 'none') {
        mode = lower;
      } else {
        network = raw.replace(/^custom:\s*/i, '').trim();
        notes.push('This container is set to use the network "' + network + '". That network ' +
          'must already exist on this server or Compose will refuse to start the stack.');
      }
    }

    var ipRaw = scalarPresent(app.MyIP) ? String(app.MyIP).trim() : '';
    var macRaw = scalarPresent(app.MyMAC) ? String(app.MyMAC).trim() : '';
    extraMac = extraMac || '';

    // --mac-address in ExtraParams is an explicit instruction; MyMAC is only
    // a template field, so the explicit one wins when both are set. Its value
    // is never format-checked below — ExtraParams values are passed through
    // untouched everywhere else in this file (e.g. --hostname, --user), and
    // this is no different.
    var macFromExtra = extraMac !== '';
    var macCandidate = macFromExtra ? extraMac : macRaw;
    if (macFromExtra && macRaw) {
      warnings.push('MyMAC ("' + macRaw + '") and --mac-address in ExtraParams ("' + extraMac +
        '") disagreed for this container; the ExtraParams value was kept.');
    }

    var result = { mode: mode, network: network, ipv4: null, mac: null };

    // host/none have no interface at all, so neither address has anywhere to
    // live. Writing one anyway would produce a file Compose starts without
    // ever applying it, which is worse than dropping it visibly.
    if (mode === 'host' || mode === 'none') {
      if (ipRaw) warnings.push('The fixed IP "' + ipRaw + '" was dropped: network_mode "' +
        mode + '" has no interface to put it on.');
      if (macCandidate) warnings.push('The fixed MAC address "' + macCandidate +
        '" was dropped: network_mode "' + mode + '" has no interface to put it on.');
      return result;
    }

    // Compose's own default network hands out its own addresses, so a fixed
    // IP has nowhere to go — but a MAC address is still a plain container
    // setting that works on that network too, so only the IP is dropped here.
    if (!network) {
      if (ipRaw) warnings.push('The fixed IP "' + ipRaw + '" was dropped: it needs a named ' +
        'network to attach to, and this container uses the default network.');
    } else if (ipRaw) {
      if (isValidIPv4(ipRaw)) result.ipv4 = ipRaw;
      else warnings.push('The fixed IP "' + ipRaw + '" is not a valid IPv4 address and was dropped.');
    }

    if (macCandidate) {
      if (macFromExtra || MAC_RE.test(macCandidate)) result.mac = macCandidate;
      else warnings.push('The fixed MAC address "' + macCandidate + '" is not a valid MAC address and was dropped.');
    }

    // Nothing failed here — this is a heads-up, not a warning — but a moved
    // address can silently collide with something else already using it.
    if (result.ipv4 || result.mac) {
      notes.push('This container keeps the fixed address it already had. Make sure nothing ' +
        'else on the network is using it.');
    }

    return result;
  }

  // The repository path out of an image reference — everything except a
  // leading registry host and a trailing tag/digest. Docker requires this
  // part to be lowercase; the host is case-insensitive and the tag is
  // case-sensitive and legitimately mixed-case, so both must be stripped
  // before the check or they produce false warnings.
  //
  // Same order Icons.php's staxx_icon_candidates() uses, and for the same
  // reason: a registry with a port (host:5000/thing) must not have its port
  // mistaken for a tag, because the tag pattern only matches a colon with no
  // slash after it, up to the end of the string.
  function repositoryPath(image) {
    var bare = String(image == null ? '' : image).replace(/@sha256:.*$/, '');
    bare = bare.replace(/:[^\/]*$/, '');
    var segments = bare.split('/');
    if (segments.length > 1 && /[.:]/.test(segments[0])) segments = segments.slice(1);
    return segments.join('/');
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
      privileged: false, warnings: [], notes: []
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
      out.notes.push('Dropped these `docker run` options, which Compose already provides on its own: ' + dropped.join(', ') + '.');
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

  function processConfig(configArr, name, warnings, notes, appdataRoot) {
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
          notes.push('The port "' + label + '" had no value; used the container port ' + target + ' as the host port too.');
        }
        var udp = String(a.Mode || '').toLowerCase() === 'udp';
        // host/target ride along so reorderPortsForWebUI() can match this entry
        // against the template's own web address after the whole list is
        // built — see that function for why. They are never read by the YAML
        // emitters below, which only ever look at .content and .comment.
        ports.push({ content: '      - ' + dq(val + ':' + target + (udp ? '/udp' : '')), comment: comment, host: val, target: target });
      } else if (type === 'Path') {
        // A Path setting's Target is meant to be where it lands inside the
        // container. A handful of real templates (MQTT Explorer's SSL/config
        // settings) instead put an environment-variable-shaped name there with
        // no value — that produces a folder path that is not a path at all,
        // and Docker refuses it at start-up without saying which setting was
        // to blame. Caught here, before a placeholder value gets invented for it.
        if (target.charAt(0) !== '/') {
          warnings.push('The path setting "' + label + '" points at "' + target + '" inside the ' +
            'container, which is not a folder path, so it was skipped. Add it by hand if the ' +
            'container really needs it.');
          return;
        }
        if (val === '') {
          val = appdataRoot + name + target;
          notes.push('The path "' + label + '" had no value; used ' + val + ' as a placeholder — check it before starting the stack.');
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

  // Import puts the port the template's own web address named at the front of
  // the list, because that is the port a reader most likely cares about and a
  // list is easier to read with it first. Nothing depends on the order any
  // more — the web-page button follows the port written in the address itself
  // (PLAN_51), not the list — so this is a readability tidy at import time and
  // nothing else, done under human review and never at button-press time.
  //
  // Measured across 85 real templates the number inside the token matches
  // the host port 10 times, the container port 15 times, both 36 times
  // (they were equal) and neither 3 times — so it is checked against the
  // host first, then the container, and a template in that last group is
  // left in its original order with a note rather than given a guess.
  function reorderPortsForWebUI(ports, webui, notes) {
    if (ports.length <= 1) return;                      // already the only choice
    var raw = scalarPresent(webui) ? String(webui) : '';
    var m = /\[PORT:([^\]]*)\]/i.exec(raw);
    if (!m) return;                                      // no token — nothing to go on
    var token = m[1].trim();
    if (token === '') return;

    var idx = -1, i;
    for (i = 0; i < ports.length; i++) { if (ports[i].host === token) { idx = i; break; } }
    if (idx < 0) {
      for (i = 0; i < ports.length; i++) { if (ports[i].target === token) { idx = i; break; } }
    }
    if (idx < 0) {
      notes.push('The web address names port ' + token + ', which this template does not list ' +
        'among its ports; the first port listed will be the one the web page button opens instead.');
      return;
    }
    if (idx > 0) ports.unshift(ports.splice(idx, 1)[0]);
  }

  /* =====================================================================
   * Assembly
   * ===================================================================== */

  function pushListBlock(svc, key, arr) {
    if (!arr.length) return;
    svc.push('    ' + key + ':');
    for (var i = 0; i < arr.length; i++) svc.push('      - ' + scalarOut(arr[i]));
  }

  // Docker's own rule for a container name — unrelated to, and looser than,
  // staxx_valid_name() (which governs the stack/service name and must stay
  // lowercase because a Compose project name must).
  var DOCKER_NAME_RE = /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/;

  // The container name is the one thing outside the stack can refer to — a
  // reverse proxy, a script, another container — so it has to survive the
  // conversion unchanged, capitals and all, or the handover that is meant to
  // replace the old container finds nothing with that name to replace. Only
  // a Name Docker would refuse outright (a space, for instance) falls back
  // to the sanitised form used for the service/stack name.
  function containerNameFor(app, fallback) {
    var raw = typeof app.Name === 'string' ? app.Name : '';
    return DOCKER_NAME_RE.test(raw) ? raw : fallback;
  }

  function convert(app, opts) {
    app = app || {};
    opts = opts || {};
    // The box's own Docker settings decide where appdata lives — hardcoding
    // the Unraid stock path would be wrong the day someone moves it to a
    // pool. "ends in exactly one slash" keeps the no-separator concatenation
    // below ('root + name + target', target already starts with '/') working
    // whatever the caller passed in.
    var appdataRoot = (typeof opts.appdataRoot === 'string' && opts.appdataRoot !== '')
      ? opts.appdataRoot : '/mnt/user/appdata/';
    appdataRoot = appdataRoot.replace(/\/*$/, '/');
    var warnings = [];
    var notes = [];
    var name = normaliseName(app.Name);
    var service = name;

    // Parsed before networkInfo() so a fixed address can be checked against
    // ExtraParams' --mac-address — the two can name conflicting MACs, and
    // that precedence is networkInfo()'s job, not this function's.
    var extra = parseExtraParams(app.ExtraParams || '');
    warnings = warnings.concat(extra.warnings);
    notes = notes.concat(extra.notes);
    var net = networkInfo(app, notes, warnings, extra.macAddress);
    var cfg = processConfig(app.Config, name, warnings, notes, appdataRoot);
    reorderPortsForWebUI(cfg.ports, app.WebUI, notes);

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
    // scalarPresent(), not bare truthiness: an empty <Repository/> element
    // arrives as an empty array (see scalarPresent()'s own comment), which
    // is truthy, and would otherwise emit "image:" with no value while also
    // suppressing the "no image name" note below.
    if (scalarPresent(app.Repository)) {
      var repository = String(app.Repository);
      svc.push('    image: ' + scalarOut(repository));
      // Written through unchanged either way — lowercasing it would be a
      // guess at a registry path nobody has checked exists (see the module
      // comment on ExtraParams for why a guessed "fix" is worse than none).
      //
      // Some feed entries are Unraid plugins, not containers: their
      // Repository is a .plg download URL, never a Docker image reference,
      // so a "://" rules those out before the check even runs.
      if (repository.indexOf('://') === -1 && /[A-Z]/.test(repositoryPath(repository))) {
        notes.push('The image "' + repository + '" has an uppercase letter in its ' +
          'repository name. Docker requires repository names to be lowercase and will refuse ' +
          'to pull this image as written — check the app\'s own page for the correct name.');
      }
    } else {
      notes.push('This template has no image name. Check "image:" before starting the stack.');
      svc.push('    image: ' + name);
    }
    svc.push('    container_name: ' + scalarOut(containerNameFor(app, name)));
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
    if (scalarPresent(app.PostArgs)) svc.push('    command: ' + scalarOut(app.PostArgs));
    if (extra.runtime) svc.push('    runtime: ' + scalarOut(extra.runtime));
    if (extra.platform) svc.push('    platform: ' + scalarOut(extra.platform));
    if (extra.readOnly) svc.push('    read_only: true');
    if (extra.init) svc.push('    init: true');
    if (extra.pid) svc.push('    pid: ' + scalarOut(extra.pid));
    if (extra.ipc) svc.push('    ipc: ' + scalarOut(extra.ipc));
    if (extra.cgroup) svc.push('    cgroup: ' + scalarOut(extra.cgroup));
    // Once a named network exists, the map form under `networks:` carries the
    // MAC instead (see below) — writing it here too would set it twice.
    if (net.mac && !net.network) svc.push('    mac_address: ' + scalarOut(net.mac));
    if (extra.stopSignal) svc.push('    stop_signal: ' + scalarOut(extra.stopSignal));
    if (extra.stopGracePeriod) svc.push('    stop_grace_period: ' + scalarOut(extra.stopGracePeriod));
    if (extra.shmSize) svc.push('    shm_size: ' + scalarOut(extra.shmSize));

    if (net.mode) {
      svc.push('    network_mode: ' + net.mode);
    } else if (net.network) {
      svc.push('    networks:');
      if (net.ipv4 || net.mac) {
        // The map form is the only one that can carry an address — a plain
        // list entry has no key to hang ipv4_address/mac_address off.
        svc.push('      ' + keyOut(net.network) + ':');
        if (net.ipv4) svc.push('        ipv4_address: ' + scalarOut(net.ipv4));
        if (net.mac)  svc.push('        mac_address: ' + scalarOut(net.mac));
      } else {
        svc.push('      - ' + scalarOut(net.network));
      }
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

    // project/support are written here too, not only at stack level below:
    // the schema's service-level keys exist so a multi-service stack (a
    // catalogue app importing several containers together) can give each
    // service its own project page rather than all of them sharing the
    // stack's one link. readme has no service-level key — it stays stack-only.
    var svcProject = scalarPresent(app.Project) ? scalarOut(app.Project) : '';
    var svcSupport = scalarPresent(app.Support) ? scalarOut(app.Support) : '';
    if (scalarPresent(app.WebUI) || svcProject || svcSupport) {
      svc.push('    x-unraid:');
      if (scalarPresent(app.WebUI)) svc.push('      webui: ' + dq(app.WebUI));
      if (svcProject) svc.push('      project: ' + svcProject);
      if (svcSupport) svc.push('      support: ' + svcSupport);
    }

    /* ---- stack-level x-unraid ------------------------------------------ */

    var stackMeta = ['  version: 1'];
    if (scalarPresent(app.Icon)) stackMeta.push('  icon: ' + scalarOut(app.Icon));
    var category = normaliseCategory(app);
    if (category) stackMeta.push('  category: ' + scalarOut(category));
    // scalarPresent(), not bare truthiness — same empty-XML-element bug as
    // Repository above: an empty <Project/>/<Support/>/<ReadMe/> is an empty
    // array, which is truthy, and would otherwise write "project:" etc with
    // no value.
    if (scalarPresent(app.Project)) stackMeta.push('  project: ' + scalarOut(app.Project));
    if (scalarPresent(app.Support)) stackMeta.push('  support: ' + scalarOut(app.Support));
    if (scalarPresent(app.ReadMe)) stackMeta.push('  readme: ' + scalarOut(app.ReadMe));
    var author = scalarPresent(app.Author) ? app.Author : (scalarPresent(app.Repo) ? app.Repo : '');
    if (scalarPresent(author)) stackMeta.push('  author: ' + scalarOut(author));
    var overview = app.Overview ? cleanOverview(app.Overview) : '';
    if (overview) {
      stackMeta.push('  overview: |');
      wrapText(overview, 78).forEach(function (l) { stackMeta.push('    ' + l); });
    }

    /* ---- whole file ------------------------------------------------------ */

    // The importer runs this same converter on a user's own Unraid template,
    // not just a Community Applications catalogue entry — and "Converted from
    // the Community Applications template" is simply false there, since that
    // template was never in the CA feed at all.
    var subject = app.Name || name;
    var firstLine = opts.origin === 'template'
      ? '# Converted from the Unraid template for ' + subject + '.'
      : '# Converted from the Community Applications template for ' + subject + '.';

    var lines = [];
    lines.push(firstLine);
    lines.push('#');
    lines.push('# This is an ordinary compose file — delete every x-unraid block below and');
    lines.push('# it still runs with a plain `docker compose up`. Check the ports and paths');
    lines.push('# before starting it; some may have been filled in with placeholder defaults.');
    if (warnings.length) {
      lines.push('#');
      lines.push('# Could not be translated automatically:');
      lines = lines.concat(warningCommentLines(warnings));
    }
    if (notes.length) {
      lines.push('#');
      lines.push('# Filled in for you — check these before starting:');
      lines = lines.concat(warningCommentLines(notes));
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

    return { name: name, service: service, yaml: yaml, warnings: warnings, notes: notes };
  }

  /* =====================================================================
   * Export — dual target exactly like compose-model.js
   * ===================================================================== */

  var API = {
    convert: convert,
    normaliseName: normaliseName,
    parseExtraParams: parseExtraParams,
    // Those below are string helpers with no CA-specific knowledge in them at
    // all — image-import.js reuses them rather than keeping a second copy of
    // the quoting rules, which is where the anchor and trailing-colon bugs
    // both lived. If a third caller ever wants them, move them somewhere
    // neutral instead of leaving them here as CA-flavoured exports.
    repositoryPath: repositoryPath,
    dq: dq,
    scalarOut: scalarOut,
    wrapText: wrapText,
    warningCommentLines: warningCommentLines
  };

  if (typeof window !== 'undefined') window.StaxxCA = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
