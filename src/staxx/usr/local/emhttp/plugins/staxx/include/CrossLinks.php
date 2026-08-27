<?PHP
/* StaXX — pointing a new stack at a database that already exists.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_70 stage 5: a value typed into a box (a hostname, a URL, a
 * "host:port") may already name a service running in a DIFFERENT stack on
 * this machine. This file works out what a value could be pointing at, and,
 * for exactly one confirmed target, what its username and password settings
 * are.
 *
 * THE ONE RULE THIS FILE EXISTS TO KEEP: matching returns names, stacks,
 * networks and ports — never a value. Credentials come back only for the one
 * stack-and-service the caller names, never a survey of every stack on the
 * box. A VALUE comes back only for a setting whose meaning is already
 * settled — the shipped images table, or this installation's own learned
 * answer for one it did not recognise; an unrecognised image gets setting
 * NAMES only, so the person can point at the password without this file
 * ever handing one back on a guess. And the direction is one-way: this
 * reads other stacks' files and never writes to any of them — nothing in
 * this file opens a STACK'S OWN file for writing. staxx_crosslinks_learn()
 * is the one exception worth naming explicitly: it writes, but only to
 * this installation's own settings (STAXX_CFG on flash), never to any
 * stack's compose file — see its own comment for why that is not the same
 * thing.
 *
 * THE IMAGES TABLE lives in data/db-images.json, read below with json_decode
 * and cached the way include/Icons.php caches its own index — the one copy
 * javascript/db-images.js also reads (handed to it on the page; see
 * StacksPage.php), so the server and the browser can no longer drift apart.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
// For staxx_cfg_write_keys() and staxx_cfg() — the learned-images table
// below is kept as this installation's own setting, not a second store.
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';

if (defined('STAXX_CROSSLINKS_LOADED')) return;
define('STAXX_CROSSLINKS_LOADED', true);

/** Where the shared table lives — read by this file and handed to the
 *  browser by StacksPage.php; see either for how. */
const STAXX_DB_IMAGES_FILE = STAXX_ROOT.'/data/db-images.json';

/** The cfg key one person's own answer for an image the shipped table does
 *  not recognise is kept under — see staxx_crosslinks_learned_table()'s own
 *  comment for why it lives here rather than in any one stack's file. */
const STAXX_DB_IMAGES_LEARNED_KEY = 'DB_IMAGES_LEARNED';

/**
 * The well-known-images table, decoded once per request and cached — same
 * pattern as staxx_icon_index() in include/Icons.php. Returns 'ok' => false
 * with a full-sentence 'error' when the file is missing or not valid JSON,
 * so a broken deploy is reported rather than read as "no image is known",
 * which would look exactly like a working system that never matches
 * anything.
 *
 * @return array{ok:bool, error:string, entries:array}
 */
function staxx_db_images_table(): array {
  static $table = null;
  if ($table !== null) return $table;

  $raw = @file_get_contents(STAXX_DB_IMAGES_FILE);
  if ($raw === false) {
    return $table = ['ok' => false, 'error' => 'The list of known database images is missing, so StaXX cannot offer the username and password for a database. Reinstall the plugin to put it back.', 'entries' => []];
  }

  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['images']) || !is_array($data['images'])) {
    return $table = ['ok' => false, 'error' => 'The list of known database images is damaged, so StaXX cannot offer the username and password for a database. Reinstall the plugin to replace it.', 'entries' => []];
  }

  return $table = ['ok' => true, 'error' => '', 'entries' => $data['images']];
}

/**
 * A leading path segment is a registry host, not a Docker Hub namespace, when
 * it looks like one — same test as db-images.js's looksLikeRegistry().
 */
function staxx_crosslinks_looks_like_registry(string $segment): bool {
  return $segment === 'localhost' || strpos($segment, '.') !== false || strpos($segment, ':') !== false;
}

/**
 * An image reference as written in a compose file, reduced to the bare
 * "name" or "namespace/name" form the images table is keyed on — the
 * PHP twin of db-images.js's normaliseRef(). Kept in step with it by hand,
 * same as the table itself.
 */
function staxx_crosslinks_normalise_ref(string $ref): string {
  $ref = trim($ref);
  if ($ref === '') return '';

  // Digest pin: "name@sha256:...". Whatever is after "@" is never part of
  // the name.
  $at = strpos($ref, '@');
  $withoutDigest = $at !== false ? substr($ref, 0, $at) : $ref;

  $segments = explode('/', $withoutDigest);
  if (count($segments) > 1 && staxx_crosslinks_looks_like_registry($segments[0])) {
    $segments = array_slice($segments, 1);
  }

  // The tag, if any, is only ever on the last segment, after its last colon
  // — a registry port was already stripped above, so a colon reaching here
  // is always a tag separator.
  $lastIdx = count($segments) - 1;
  $colon   = strrpos($segments[$lastIdx], ':');
  if ($colon !== false) $segments[$lastIdx] = substr($segments[$lastIdx], 0, $colon);

  // "library/x" is Docker Hub's implicit namespace for official images.
  if (count($segments) === 2 && $segments[0] === 'library') {
    $segments = array_slice($segments, 1);
  }

  return implode('/', $segments);
}

/** The table entry for an image reference, or null when it names nothing
 *  $entries recognises. $entries is staxx_db_images_table()'s 'entries'. */
function staxx_crosslinks_lookup_image(string $ref, array $entries): ?array {
  $name = staxx_crosslinks_normalise_ref($ref);
  if ($name === '') return null;
  foreach ($entries as $entry) {
    if (in_array($name, $entry['images'], true)) return $entry;
  }
  return null;
}

/**
 * One installation's own answers for images the shipped table does not
 * recognise (PLAN_70 10.2's "otherwise, ask once") — keyed by the same
 * normalised image name staxx_crosslinks_lookup_image() matches against.
 * Kept as an ordinary StaXX setting (STAXX_CFG, via staxx_cfg()), the same
 * file every other installation-wide preference already lives in, never in
 * any one stack's file — a write there is exactly what PLAN_70 10.5
 * forbids, and the answer is really about the image, not any one stack, so
 * every future stack running it benefits from one person's click.
 *
 * The value itself is base64-wrapped JSON in a single cfg key:
 * staxx_cfg_write_keys() writes a plain KEY="value" line with no escaping,
 * and JSON is made of the one character (") that line cannot hold.
 *
 * Never throws and never reports an error of its own — unlike
 * staxx_db_images_table() above, a missing or corrupt learned table is not
 * a broken deploy, just nothing learned yet, so this quietly returns [].
 *
 * @return array<string, array{user?:string, password?:string}>
 */
function staxx_crosslinks_learned_table(): array {
  // Read straight off disk rather than through staxx_cfg(): that function
  // caches per-request in a static, so a learn() and a credentials() call
  // in the very same request — exactly what tests/server/links_match.php
  // does — would otherwise see whatever this key held BEFORE the write
  // that just happened. staxx_cfg_write_keys() already reads STAXX_CFG the
  // same raw way internally; this is that same, cheap, uncached read, not
  // a second mechanism — this key has no default.cfg entry for staxx_cfg()
  // to usefully merge in anyway.
  $cfg = @parse_ini_file(STAXX_CFG, false, INI_SCANNER_RAW) ?: [];
  $raw = (string)($cfg[STAXX_DB_IMAGES_LEARNED_KEY] ?? '');
  if ($raw === '') return [];
  $json = base64_decode($raw, true);
  if ($json === false) return [];
  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}

/**
 * staxx_crosslinks_lookup_image() first, then this installation's own
 * learned additions — never the other order, so a person's own answer can
 * never shadow a documented, checked shipped entry.
 */
function staxx_crosslinks_lookup_image_any(string $ref, array $shippedEntries): ?array {
  $found = staxx_crosslinks_lookup_image($ref, $shippedEntries);
  if ($found !== null) return $found;

  $name = staxx_crosslinks_normalise_ref($ref);
  if ($name === '') return null;
  $learned = staxx_crosslinks_learned_table();
  if (!isset($learned[$name]) || !is_array($learned[$name])) return null;

  $entry = ['id' => $name, 'images' => [$name]];
  foreach (['user', 'password'] as $slot) {
    if (!empty($learned[$name][$slot]) && is_string($learned[$name][$slot])) {
      $entry[$slot] = [$learned[$name][$slot]];
    }
  }
  return $entry;
}

/* ============================================================================
 * Reading a stack's full configuration — beyond what staxx_compose_meta()
 * keeps, which is deliberately narrow (image, container_name, the FIRST
 * published port). Matching a network needs every network a service joins,
 * and reading a credential needs the actual environment values, so this runs
 * `compose config` a second time for the one or two stacks a match actually
 * concerns — never for the whole machine, which is what keeps this cheap.
 * ========================================================================= */

/**
 * The normalised `compose config` text for one stack's file, or '' when
 * compose is not installed or the file does not parse. In-request cache
 * only — staxx_compose_meta() already pays for the on-disk cache this would
 * otherwise duplicate, and this is called for at most a handful of stacks
 * per request.
 */
function staxx_crosslinks_config_yaml(string $file): string {
  static $cache = [];

  $files = staxx_compose_files($file);
  if ($files === []) return '';
  $key = implode("\0", $files);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $cmd = staxx_compose_cmd();
  if ($cmd === '') return $cache[$key] = '';

  $code = 1;
  $out  = staxx_sh($cmd.' '.staxx_compose_file_args($files).' config 2>&1', 15, $code);
  return $cache[$key] = ($code === 0 ? $out : '');
}

/**
 * Every service's network attachments, but ONLY the networks that could ever
 * be shared with another stack: those declared `external: true` at the top
 * of the file. Compose namespaces every other network — including the
 * synthetic `default` one every service joins when the file says nothing —
 * to this project alone, so two stacks can never actually share one however
 * similar their names look; leaving those out entirely is what keeps a
 * same-name coincidence from reading as a real connection.
 *
 * Resolves each external network to the real, on-host name it runs under:
 * the network's own `name:` when the file sets one, otherwise the key it is
 * declared under (compose's own rule for an external network with no name
 * override).
 *
 * @return array<string, string[]> service name => real network names
 */
function staxx_crosslinks_service_networks(string $file): array {
  $yaml = staxx_crosslinks_config_yaml($file);
  if ($yaml === '') return [];

  $flat = staxx_yaml_flatten($yaml);

  $isExternal = [];   // top-level network key => true
  $realName   = [];   // top-level network key => its own name: value, if any
  foreach ($flat as $path => $value) {
    $parts = explode("\0", $path);
    if ($parts[0] !== 'networks' || count($parts) < 3) continue;
    $key = $parts[1];
    if ($parts[2] === 'external' && count($parts) === 3 && $value === 'true') {
      $isExternal[$key] = true;
    } elseif ($parts[2] === 'name' && count($parts) === 3 && $value !== '') {
      $realName[$key] = $value;
    }
  }

  $external = [];      // key => real, on-host name
  foreach ($isExternal as $key => $_) {
    $external[$key] = $realName[$key] ?? $key;
  }
  if ($external === []) return [];

  $byService = [];
  foreach ($flat as $path => $value) {
    $parts = explode("\0", $path);
    if ($parts[0] !== 'services' || count($parts) < 4 || $parts[2] !== 'networks') continue;
    $netKey = $parts[3];
    if (!isset($external[$netKey])) continue;
    $service = $parts[1];
    $byService[$service][] = $external[$netKey];
  }
  foreach ($byService as &$list) $list = array_values(array_unique($list));
  unset($list);

  return $byService;
}

/**
 * The real network names one service of the SOURCE stack — the one the
 * person is editing — is attached to. An empty $service asks "any service
 * in this stack", which is the reasonable fallback when the caller does not
 * know which service's box is being filled in.
 *
 * @return string[]
 */
function staxx_crosslinks_source_networks(string $file, string $service): array {
  $byService = staxx_crosslinks_service_networks($file);

  if ($service !== '') return $byService[$service] ?? [];

  $all = [];
  foreach ($byService as $list) $all = array_merge($all, $list);
  return array_values(array_unique($all));
}

/**
 * Split a value into a host and a port, the way a URL or a bare "host:port"
 * would be read. The second element of the return is '' when the value
 * carries no port; the third says whether the value looked like a URL or a
 * host:port at all — a plain name such as "db" is neither, and is left for
 * the name-equality check alone.
 *
 * @return array{0:string,1:string,2:bool} [host, port, looksLikeHostPort]
 */
function staxx_crosslinks_split_host_port(string $value): array {
  $rest = $value;

  if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $rest)) {
    $rest = preg_replace('#^[A-Za-z][A-Za-z0-9+.-]*://#', '', $rest, 1);
  } elseif (strpos($rest, '/') === false && strpos($rest, ':') === false) {
    return ['', '', false];   // a bare name — nothing here to split
  }

  // Userinfo ("user:pass@host") is never the host.
  $at = strrpos($rest, '@');
  if ($at !== false) $rest = substr($rest, $at + 1);

  // The authority ends at the first slash, query or fragment.
  $cut       = strcspn($rest, '/?#');
  $authority = substr($rest, 0, $cut);
  if ($authority === '') return ['', '', false];

  // IPv6 written in brackets: "[::1]:3306" or bare "[::1]".
  if ($authority[0] === '[') {
    $close = strpos($authority, ']');
    if ($close === false) return ['', '', false];
    $host = substr($authority, 1, $close - 1);
    $tail = substr($authority, $close + 1);
    $port = (strlen($tail) > 1 && $tail[0] === ':') ? substr($tail, 1) : '';
    return [$host, $port, true];
  }

  $colon = strrpos($authority, ':');
  if ($colon === false) return [$authority, '', true];

  $host = substr($authority, 0, $colon);
  $port = substr($authority, $colon + 1);
  // A port is digits only — anything else reaching here (an unbracketed
  // IPv6 address, most likely) means this was not host:port shaped after
  // all, so the whole authority is handed back as the host instead.
  if (!preg_match('/^\d{1,5}$/', $port)) return [$authority, '', true];

  return [$host, $port, true];
}

/** `localhost`, `127.0.0.1` or `::1` — see staxx_crosslinks_match()'s own
 *  comment for why these get their own answer rather than a silent no-match. */
function staxx_crosslinks_is_loopback(string $host): bool {
  $host = strtolower(trim($host));
  return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}

/** Is $host this machine's own address or hostname? Best-effort: the LAN
 *  address Unraid records for itself, or the box's own hostname. */
function staxx_crosslinks_is_this_server(string $host): bool {
  $host = trim($host);
  if ($host === '') return false;
  if ($host === staxx_host_ip()) return true;
  $hostname = gethostname();
  return $hostname !== false && $hostname !== '' && strcasecmp($host, $hostname) === 0;
}

/** Is $value shaped like an address literal — IPv4 or IPv6, no scheme, no
 *  port? Used to gate PLAN_94 part B: staxx_crosslinks_split_host_port() only
 *  ever pulls a host out of a URL or a "host:port" pair, so a bare address on
 *  its own ("192.168.30.40", nothing else) needs checking directly instead. */
function staxx_crosslinks_looks_like_address(string $value): bool {
  return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

/**
 * Per service, the fixed address it declares on an EXTERNAL network —
 * external because that is the only kind another stack could ever be told
 * about at all, the same gate staxx_crosslinks_service_networks() applies —
 * together with whether that network's driver is macvlan or ipvlan.
 *
 * The address itself comes straight out of this file's own `compose config`
 * text, same as staxx_compose_meta()'s fixedIp. The DRIVER cannot: a real
 * external network's own stanza conventionally carries nothing but
 * `external: true` (and maybe a `name:` override) — see
 * tests/fixtures/test-stacks/03-multi-tier for the shape Unraid's own br0.x
 * networks take in practice — so there is no `driver:` line in the file to
 * read. This asks Docker instead, via staxx_network_drivers(), the same live
 * lookup staxx_service_net_kind() already relies on elsewhere to tell a
 * bridge from a macvlan network. That is a question about what a network
 * already IS, not a test of whether anything can reach it: no packet of any
 * kind leaves this function, so PLAN_94's "nothing probes the network" still
 * holds — it is the reachability itself that is never tested, not the
 * metadata never consulted.
 *
 * Same "first one found wins" rule as staxx_compose_meta()'s own fixedIp: a
 * service fixed to two addresses is not a case worth ranking.
 *
 * $drivers is the network-name => driver map to judge against, and exists so
 * the server-side suite can prove this route offline: the alternative is
 * creating real macvlan networks on the machine running the tests, which on
 * the development box is somebody's live server.
 *
 * @return array<string, array{ip:string, vlan:bool}>
 */
function staxx_crosslinks_service_fixed_vlan(string $file, ?array $drivers = null): array {
  $yaml = staxx_crosslinks_config_yaml($file);
  if ($yaml === '') return [];
  $flat = staxx_yaml_flatten($yaml);

  $isExternal = [];   // top-level network key => true
  $realName   = [];   // top-level network key => its own name: value, if any
  foreach ($flat as $path => $value) {
    $parts = explode("\0", $path);
    if ($parts[0] !== 'networks' || count($parts) !== 3) continue;
    if ($parts[2] === 'external' && $value === 'true') $isExternal[$parts[1]] = true;
    elseif ($parts[2] === 'name' && $value !== '') $realName[$parts[1]] = $value;
  }

  $drivers = $drivers ?? staxx_network_drivers();
  $out = [];
  foreach ($flat as $path => $value) {
    $parts = explode("\0", $path);
    if ($parts[0] !== 'services' || count($parts) !== 5
        || $parts[2] !== 'networks' || $parts[4] !== 'ipv4_address') continue;
    $service = $parts[1];
    $netKey  = $parts[3];
    if (isset($out[$service]) || !isset($isExternal[$netKey])) continue;
    $realNetName = $realName[$netKey] ?? $netKey;
    $driver = $drivers[$realNetName] ?? '';
    $out[$service] = ['ip' => $value, 'vlan' => ($driver === 'macvlan' || $driver === 'ipvlan')];
  }
  return $out;
}

/** Lower-cased with every non-alphanumeric character stripped, so
 *  "sup-stack-db", "sup_stack_db" and "SupStackDB" all reduce to the same
 *  string. PLAN_94 part C's first, safe fallback pass — see
 *  staxx_crosslinks_match()'s own comment for the second, looser one. */
function staxx_crosslinks_loose_name(string $s): string {
  return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $s));
}

/**
 * What could a value typed into a box, in $sourcePath's $sourceService, be
 * pointing at?
 *
 * See PLAN_70 10.1 and PLAN_94. Up to five routes are tried, in this order:
 *
 *   1. localhost/127.0.0.1/::1 (or a URL/host:port naming one of them) —
 *      checked first and returned alone: inside a container that address can
 *      only ever mean the container itself, however the networks are
 *      arranged.
 *   2. the value equals another stack's service name, or its container_name,
 *      AND the two stacks share an external network — the only condition
 *      under which that name would actually resolve. (PLAN_94 part A: when
 *      the name matches but the network does not, that is now its own reply
 *      — see 'unreachable' below — rather than being dropped in silence.)
 *   3. the value (or a URL/host:port's host half) is this server's own
 *      address or hostname, together with a port another stack publishes —
 *      no shared network needed for this route.
 *   4. (PLAN_94 part B) the value is an address literal, with or without a
 *      port, equal to another stack's service's OWN DECLARED fixed address —
 *      but only where that address sits on a macvlan or ipvlan network. A
 *      fixed address on a bridge network is invisible from outside that
 *      network and must not match. The address itself always comes from the
 *      file; the network's driver is asked of Docker (see
 *      staxx_crosslinks_service_fixed_vlan()'s own comment for why a real
 *      external network's file entry does not carry that itself). Nothing
 *      here tests whether the two can actually reach each other — no packet
 *      of any kind leaves this function.
 *   5. (PLAN_94 part C) "did you mean" — only once routes 2-4 found nothing
 *      reachable at all, not even an unreachable one. Ignoring separators and
 *      case first; only if that also finds nothing, allow up to two
 *      characters' difference, and only for a name longer than three
 *      characters — below that, two characters is most of the name, so no
 *      distance pass runs at all. At most three suggestions, ones that share
 *      a network with the asker first.
 *
 * Returns NAMES, STACKS, NETWORKS AND PORTS ONLY. No value from any stack's
 * file appears anywhere in this function's return, and no shared secret is
 * ever touched here — staxx_crosslinks_credentials() is the one place a
 * value comes back, and only for exactly one confirmed target.
 *
 * THE REPLY SHAPE — a contract with the browser, so every field it may rely
 * on is spelled out here rather than left to be inferred from a diff:
 *
 *   kind === 'self'
 *     A loopback address. 'reason' is a full sentence, ready to show as-is.
 *     'candidates' is always [].
 *
 *   kind === 'match'
 *     One or more real, reachable candidates. 'reason' is '' — the browser
 *     already knows how to word each 'via'. Each candidate:
 *       {stack, service,
 *        via: 'service-name'|'container-name'|'port'|'fixed-address',
 *        network?: string,   // 'service-name'/'container-name' only — the
 *                             // network the two stacks actually share
 *        port?: string}      // 'port' only — the published port matched
 *     A candidate here is the only kind of reply
 *     staxx_crosslinks_credentials() may be called against.
 *
 *   kind === 'unreachable'   (PLAN_94 part A — new; an old caller never sees
 *                             this kind, so it cannot break on it)
 *     A name matched a service in another stack, but the two stacks share no
 *     network. 'reason' is a full sentence saying so and, where the target
 *     service has one, naming the network that would connect them.
 *     'candidates' is always []. A 'blocked' field carries the same facts
 *     structured, for a browser that wants to build its own offer rather
 *     than just show the sentence: {stack, service, network: string|null} —
 *     network is the target service's own network (its first declared
 *     external network; never a survey of every network it touches, and
 *     never one that only exists inside a third stack), or null when it has
 *     none to offer. Nothing here writes to the other stack's file, or to
 *     this one's.
 *
 *   kind === 'none'
 *     Nothing reachable, and no exact name exists anywhere either. 'reason'
 *     is '' and 'candidates' is [], exactly as before this plan. A
 *     'suggestions' field MAY also be present (PLAN_94 part C — an old
 *     caller can safely ignore a field it has never heard of): at most 3
 *     entries, each
 *       {stack, service, via: 'service-name'|'container-name',
 *        network: string|null}
 *     network names what the two stacks already share, or null when they
 *     share nothing. A suggestion is never a match: it cannot be acted on by
 *     itself, it carries no credential, and confirming one still means
 *     naming the target explicitly — exactly as typing it in directly does.
 *
 * @return array{kind:string, reason:string, candidates:array}
 */
function staxx_crosslinks_match(string $sourcePath, string $sourceService, string $value,
                                ?array $drivers = null): array {
  $value = trim($value);
  if ($value === '' || !staxx_valid_path($sourcePath)) {
    return ['kind' => 'none', 'reason' => '', 'candidates' => []];
  }

  [$host, $port, $hasHostPort] = staxx_crosslinks_split_host_port($value);
  $loopbackCheck = $hasHostPort ? $host : $value;

  if (staxx_crosslinks_is_loopback($loopbackCheck)) {
    return [
      'kind'   => 'self',
      'reason' => 'Inside a container, "'.$loopbackCheck.'" always means the container itself — '
                . 'it can never reach a different container, however the networks are arranged. '
                . 'Point it at the other container\'s name or this server\'s own address instead.',
      'candidates' => [],
    ];
  }

  $sourceFile = staxx_find_compose_file(staxx_stack_dir($sourcePath));
  $sourceNets = $sourceFile !== '' ? staxx_crosslinks_source_networks($sourceFile, $sourceService) : [];

  $nameCandidates = [$value];
  if ($hasHostPort && $host !== '') $nameCandidates[] = $host;
  $nameCandidates = array_values(array_unique($nameCandidates));
  // Docker's own name resolution ignores case — "supstack-db" and
  // "SupStack-DB" both reach the same container, confirmed by asking a
  // running container to look up each in turn. So a name typed in the wrong
  // case is a working address, and refusing to recognise it was a miss
  // rather than caution. Matched case-insensitively for that reason and no
  // other: this is not fuzzy matching, and a shared SECRET is still compared
  // exactly, because two passwords differing in case are two passwords.
  $nameLower = array_map('strtolower', $nameCandidates);
  $nameLoose = array_map('staxx_crosslinks_loose_name', $nameCandidates);

  // Part B needs an address to compare whether or not the value happened to
  // carry a port. split_host_port() only ever pulls a host out of a URL or a
  // "host:port" pair, so a bare address on its own is checked directly.
  $addressHost = '';
  if ($hasHostPort && $host !== '' && staxx_crosslinks_looks_like_address($host)) {
    $addressHost = $host;
  } elseif (!$hasHostPort && staxx_crosslinks_looks_like_address($value)) {
    $addressHost = $value;
  }

  $candidates = [];
  $nearMiss   = null;   // part A — first exact name match with no shared network
  $fuzzyLoose = [];     // part C pass 1 — separators and case ignored
  $fuzzyNear  = [];     // part C pass 2 — up to 2 characters' difference
  $thisServer = $hasHostPort && $port !== '' && staxx_crosslinks_is_this_server($host);

  foreach (staxx_list_stacks() as $stack) {
    if ($stack['name'] === $sourcePath || $stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    // Read once per stack, not once per service: the flatten behind it is
    // the same work whichever service asked for it.
    $fixedByService = $addressHost !== ''
      ? staxx_crosslinks_service_fixed_vlan($stack['file'], $drivers) : [];

    foreach ($meta['services'] as $svcName => $svc) {
      // Route 2 — a name match, gated on a real shared network.
      $via = null;
      if (in_array(strtolower($svcName), $nameLower, true)) {
        $via = 'service-name';
      } elseif ((string)$svc['container_name'] !== ''
                && in_array(strtolower($svc['container_name']), $nameLower, true)) {
        $via = 'container-name';
      }
      if ($via !== null) {
        $targetNets = staxx_crosslinks_service_networks($stack['file'])[$svcName] ?? [];
        $shared     = array_values(array_intersect($sourceNets, $targetNets));
        if ($shared !== []) {
          $candidates[] = [
            'stack'   => $stack['name'],
            'service' => $svcName,
            'via'     => $via,
            'network' => $shared[0],
          ];
        } elseif ($nearMiss === null) {
          // Part A: the name is real, it just cannot be reached from here —
          // say so, naming the target's own network, rather than falling
          // through to silence. First one found wins, same as everywhere
          // else in this file that has to pick just one.
          $nearMiss = ['stack' => $stack['name'], 'service' => $svcName, 'network' => $targetNets[0] ?? null];
        }
      }

      // Route 3 — this server's address plus a port the service publishes.
      if ($thisServer) {
        $published = (string)($svc['firstPort']['published'] ?? '');
        if ($published !== '' && $published === $port) {
          $candidates[] = [
            'stack'   => $stack['name'],
            'service' => $svcName,
            'via'     => 'port',
            'port'    => $published,
          ];
        }
      }

      // Route 4 (part B) — a declared fixed address, macvlan/ipvlan only.
      if ($addressHost !== '') {
        $fixed = $fixedByService[$svcName] ?? null;
        if ($fixed !== null && $fixed['vlan'] && strcasecmp($fixed['ip'], $addressHost) === 0) {
          $candidates[] = ['stack' => $stack['name'], 'service' => $svcName, 'via' => 'fixed-address'];
        }
      }

      // Part C's fallback passes. Only worth trying for a name that did not
      // already match exactly above — an exact match with no shared network
      // is part A's case, not this one, and must not also turn into a
      // "suggestion" for the very same target.
      if ($via === null) {
        $targets = [['service-name', $svcName]];
        if ((string)$svc['container_name'] !== '') $targets[] = ['container-name', $svc['container_name']];

        $fuzzyShared = null;   // resolved lazily, at most once per service
        foreach ($targets as [$vk, $target]) {
          $targetLoose = staxx_crosslinks_loose_name((string)$target);
          if ($targetLoose === '') continue;
          foreach (array_keys($nameCandidates) as $i) {
            if ($nameLoose[$i] === '') continue;
            if ($fuzzyShared === null) {
              $fuzzyShared = array_values(array_intersect(
                $sourceNets, staxx_crosslinks_service_networks($stack['file'])[$svcName] ?? []
              ));
            }
            $entry = ['stack' => $stack['name'], 'service' => $svcName, 'via' => $vk,
                      'network' => $fuzzyShared[0] ?? null];
            if ($nameLoose[$i] === $targetLoose) {
              $fuzzyLoose[] = $entry;
            } elseif (strlen($nameLoose[$i]) > 3 && levenshtein($nameLoose[$i], $targetLoose) <= 2) {
              // The THREE-OR-FEWER rule is on the name itself, separators
              // stripped — "d_b" is really a two-letter name, and two
              // characters' difference is most of it either way.
              $fuzzyNear[] = $entry;
            }
          }
        }
      }
    }
  }

  if ($candidates !== []) return ['kind' => 'match', 'reason' => '', 'candidates' => $candidates];

  if ($nearMiss !== null) {
    $network = $nearMiss['network'];
    $reason = 'There is a service called "'.$nearMiss['service'].'" in the stack "'.$nearMiss['stack'].'", '
            . 'but this stack and that one share no network, so this address cannot reach it. '
            . ($network !== null
                ? 'Adding this service to the network "'.$network.'" would.'
                : 'That stack has no network of its own to add this one to, so a network would need '
                . 'to be created and added to both before they could connect.');
    return ['kind' => 'unreachable', 'reason' => $reason, 'candidates' => [], 'blocked' => $nearMiss];
  }

  $pool = $fuzzyLoose !== [] ? $fuzzyLoose : $fuzzyNear;
  if ($pool === []) return ['kind' => 'none', 'reason' => '', 'candidates' => []];

  // One service can match through both its name and its container_name —
  // worth only one suggestion, not two of the three slots.
  $seen   = [];
  $unique = [];
  foreach ($pool as $entry) {
    $dupeKey = $entry['stack']."\0".$entry['service'];
    if (isset($seen[$dupeKey])) continue;
    $seen[$dupeKey] = true;
    $unique[] = $entry;
  }

  // Same-network suggestions first, then everything else, in the order
  // found — never re-sorted by anything this file cannot see.
  $sameNetwork = array_values(array_filter($unique, fn($c) => $c['network'] !== null));
  $other       = array_values(array_filter($unique, fn($c) => $c['network'] === null));
  $suggestions = array_slice(array_merge($sameNetwork, $other), 0, 3);

  return ['kind' => 'none', 'reason' => '', 'candidates' => [], 'suggestions' => $suggestions];
}

/** One service's live environment settings — name => current value, from
 *  `compose config`'s own resolved output. Shared by
 *  staxx_crosslinks_credentials() and staxx_crosslinks_learn() below, so
 *  both read the same live values rather than each re-deriving them. */
function staxx_crosslinks_service_env(string $file, string $service): array {
  $yaml = staxx_crosslinks_config_yaml($file);
  $env  = [];
  if ($yaml !== '') {
    foreach (staxx_yaml_flatten($yaml) as $path => $value) {
      $parts = explode("\0", $path);
      if ($parts[0] === 'services' && count($parts) === 4
          && $parts[1] === $service && $parts[2] === 'environment') {
        $env[$parts[3]] = $value;
      }
    }
  }
  return $env;
}

/**
 * The username/password settings for ONE confirmed target — the only place
 * in this file a value from another stack's compose file is ever returned.
 * $stackPath and $service must name a real stack and a real service in it;
 * anything else is refused, never guessed at.
 *
 * @return array ok:false + error, or ok:true with 'known' saying whether the
 *   image is recognised — the shipped table first, then this
 *   installation's own learned additions (staxx_crosslinks_lookup_image_any())
 *   — and either 'fields', for a known image and only for the settings
 *   actually set in the target's file, each a {name, value} pair; or, for an
 *   unknown image, 'settingNames' — every environment setting name the
 *   target actually has, values withheld, so the person can point at which
 *   one is the password without this file ever handing back one it has not
 *   confirmed the meaning of.
 */
function staxx_crosslinks_credentials(string $stackPath, string $service): array {
  if (!staxx_valid_path($stackPath)) {
    return ['ok' => false, 'error' => 'Invalid stack name.'];
  }
  $dir  = staxx_stack_dir($stackPath);
  $file = staxx_find_compose_file($dir);
  if ($file === '') {
    return ['ok' => false, 'error' => 'No compose file found in this stack.'];
  }

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) {
    return ['ok' => false, 'error' => 'This stack\'s compose file could not be read.'];
  }
  if (!isset($meta['services'][$service])) {
    return ['ok' => false, 'error' => 'No service called "'.$service.'" in this stack.'];
  }

  $table = staxx_db_images_table();
  if (!$table['ok']) {
    return ['ok' => false, 'error' => $table['error']];
  }

  $image = (string)$meta['services'][$service]['image'];
  $entry = staxx_crosslinks_lookup_image_any($image, $table['entries']);
  $env   = staxx_crosslinks_service_env($file, $service);

  if ($entry === null) {
    return ['ok' => true, 'known' => false, 'image' => $image, 'settingNames' => array_keys($env)];
  }

  $fields = [];
  foreach (['user', 'password', 'rootPassword', 'database'] as $slot) {
    foreach ((array)($entry[$slot] ?? []) as $settingName) {
      if (($env[$settingName] ?? '') !== '') {
        $fields[$slot] = ['name' => $settingName, 'value' => $env[$settingName]];
        break;
      }
    }
  }

  return ['ok' => true, 'known' => true, 'image' => $image, 'fields' => $fields];
}

/**
 * Records which of one image's own settings hold its username and its
 * password — a person's own answer for an image staxx_crosslinks_credentials()
 * did not recognise (PLAN_70 10.2's "otherwise, ask once"). $stackPath and
 * $service name the same target credentials was just asked about, so the
 * image and its live settings are re-resolved here exactly the same way
 * rather than trusted from the browser.
 *
 * $fields holds only the two slots this feature ever asks a person to point
 * at — 'user' and 'password' — each either the name of a setting this
 * service's own environment actually has, or left out. At least one is
 * required: a $fields naming neither would learn nothing, so it is refused.
 *
 * Never writes into $stackPath's own file, or any stack's — PLAN_70 10.5's
 * one-way rule. The answer is kept as this installation's own setting
 * (staxx_crosslinks_learned_table()), consulted after the shipped table for
 * every future stack pointing at this image, this one included.
 *
 * @return array ok:false + error, or ok:true with 'fields' resolved fresh
 *   against this service's live values — the same shape
 *   staxx_crosslinks_credentials() returns for an already-known image — so
 *   the one click that teaches StaXX also answers the request that
 *   prompted it, with no second round trip.
 */
function staxx_crosslinks_learn(string $stackPath, string $service, array $fields, ?string &$error = null): array {
  if (!staxx_valid_path($stackPath)) {
    $error = 'Invalid stack name.';
    return ['ok' => false, 'error' => $error];
  }
  $dir  = staxx_stack_dir($stackPath);
  $file = staxx_find_compose_file($dir);
  if ($file === '') {
    $error = 'No compose file found in this stack.';
    return ['ok' => false, 'error' => $error];
  }
  $meta = staxx_compose_meta($file);
  if (!$meta['ok'] || !isset($meta['services'][$service])) {
    $error = 'No service called "'.$service.'" in this stack.';
    return ['ok' => false, 'error' => $error];
  }

  $image = (string)$meta['services'][$service]['image'];
  $name  = staxx_crosslinks_normalise_ref($image);
  if ($name === '') {
    $error = 'That image reference is not recognised.';
    return ['ok' => false, 'error' => $error];
  }

  $env = staxx_crosslinks_service_env($file, $service);

  $picked = [];
  foreach (['user', 'password'] as $slot) {
    $setting = trim((string)($fields[$slot] ?? ''));
    if ($setting === '') continue;
    if (!array_key_exists($setting, $env)) {
      $error = '"'.$setting.'" is not a setting this service actually has.';
      return ['ok' => false, 'error' => $error];
    }
    $picked[$slot] = $setting;
  }
  if ($picked === []) {
    $error = 'Point at least at the box holding the password before this can be remembered.';
    return ['ok' => false, 'error' => $error];
  }

  $table = staxx_crosslinks_learned_table();
  $table[$name] = $picked;
  $json = json_encode($table);
  if ($json === false) {
    $error = 'That could not be saved.';
    return ['ok' => false, 'error' => $error];
  }
  if (!staxx_cfg_write_keys([STAXX_DB_IMAGES_LEARNED_KEY => base64_encode($json)], $error)) {
    return ['ok' => false, 'error' => $error];
  }

  $out = [];
  foreach ($picked as $slot => $setting) {
    $out[$slot] = ['name' => $setting, 'value' => $env[$setting] ?? ''];
  }
  return ['ok' => true, 'fields' => $out];
}
?>
