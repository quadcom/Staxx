<?PHP
/* StaXX — talking to Nginx Proxy Manager and Pi-hole (PLAN_176).
 * Copyright 2026, StaXX contributors.
 *
 * One HTTP helper both services' calls go through, plus the login/logout and
 * version checks each one needs. Nothing here ever puts a password or a
 * token on the command line — `ps` on a shared server would show it to
 * anyone — so every credential travels in a curl config file (`-K`), written
 * 0600 inside a private directory and removed again the moment the call
 * returns, whichever way it returns.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
// For staxx_private_dir() — the same private-directory helper the job runner
// and the shell session use, so this shares its "always 0700, always made
// fresh" guarantee rather than re-implementing it.
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_EXPOSE_LOADED')) return;
define('STAXX_EXPOSE_LOADED', true);

// Kept under /tmp rather than inside the data store: this holds nothing
// worth surviving a reboot, and /tmp is guaranteed writable even before a
// store has ever been chosen.
define('STAXX_EXPOSE_TMP', '/tmp/staxx-expose');

/**
 * Quote one value for a curl config file. Unlike a shell, curl's `-K` syntax
 * only needs a backslash and a double quote escaped inside a quoted value —
 * done in that order, since escaping the quote first would double-escape the
 * backslash the first step just added.
 */
function staxx_expose_curl_quote(string $s): string {
  return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
}

/**
 * One HTTP call to NPM or Pi-hole. Every credential and header goes into a
 * curl config file rather than onto the command line (see file header); the
 * file is removed immediately after the call, before this function looks at
 * what came back, so a PHP error later in the function can never leave it
 * behind.
 *
 * Plain http:// is refused unless EXPOSE_ALLOW_INSECURE is "yes" — checked
 * here, not left to curl, so the refusal names the setting rather than
 * surfacing as a connection failure. With that setting on, `-k` is also
 * added, which skips the certificate check on an https:// address too: both
 * kinds of insecure connection share the one switch (Adrian, 2026-09-24).
 *
 * @param string     $method  GET, POST, PUT or DELETE
 * @param string     $url     full address, scheme included
 * @param array|null $body    sent as a JSON object; null for no body at all
 * @param string[]   $headers extra header lines, e.g. ['X-FTL-SID: …']
 * @param string     &$err    a full sentence, set only on failure
 * @return array{0:int,1:?array}  [http status code (0 = never reached it), decoded JSON body or null]
 */
function staxx_expose_http(string $method, string $url, ?array $body, array $headers, string &$err): array {
  $err = '';

  $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
  if ($scheme !== 'http' && $scheme !== 'https') {
    $err = 'That is not a valid web address.';
    return [0, null];
  }
  $cfg      = staxx_cfg();
  $insecure = ($cfg['EXPOSE_ALLOW_INSECURE'] ?? 'no') === 'yes';
  if ($scheme === 'http' && !$insecure) {
    $err = 'That address uses plain http, which StaXX will not use unless "Allow insecure '
         . 'connections" is turned on in the Integrations settings.';
    return [0, null];
  }

  if (!staxx_private_dir(STAXX_EXPOSE_TMP)) {
    $err = 'Could not make a private folder to hold this request.';
    return [0, null];
  }
  $cfgFile = STAXX_EXPOSE_TMP.'/'.bin2hex(random_bytes(8)).'.cfg';

  $lines = [];
  foreach ($headers as $h) $lines[] = 'header = '.staxx_expose_curl_quote($h);
  if ($body !== null) {
    $lines[] = 'header = '.staxx_expose_curl_quote('Content-Type: application/json');
    $lines[] = 'data = '.staxx_expose_curl_quote((string)json_encode($body));
  }
  @file_put_contents($cfgFile, implode("\n", $lines)."\n");
  @chmod($cfgFile, 0600);

  $cmd = 'curl -sS -K '.escapeshellarg($cfgFile)
       . ($insecure ? ' -k' : '')
       . ' -X '.escapeshellarg(strtoupper($method))
       . ' --connect-timeout 5 -w '.escapeshellarg("\n%{http_code}")
       . ' '.escapeshellarg($url);

  // Removed the instant the call is back, before any of the branches below
  // that might return early — "on every exit path" means this line has to
  // run before the first one of them, not be repeated in each.
  $out = staxx_sh($cmd, 15, $code);
  @unlink($cfgFile);

  if ($code === 124) {
    $err = 'Nothing answered at '.$url.' in time. Check the address and that the service is running.';
    return [0, null];
  }
  // curl exit 60: the certificate could not be verified.
  if ($code === 60) {
    $err = 'The certificate at '.$url.' could not be checked. Turn on "Allow insecure connections" '
         . 'in the Integrations settings if you trust this device anyway, or fix its certificate.';
    return [0, null];
  }
  if ($code !== 0) {
    $err = 'Could not reach '.$url.'.';
    return [0, null];
  }

  $parts  = explode("\n", $out);
  $status = (int)trim((string)array_pop($parts));
  $json   = json_decode(implode("\n", $parts), true);
  return [$status, is_array($json) ? $json : null];
}

/**
 * Sign in to Nginx Proxy Manager and return a bearer token. No caching: a
 * token lasts about a day, but one StaXX action is a single short burst of
 * calls, so logging in fresh each time is simpler than tracking expiry.
 */
function staxx_npm_login(string &$err): string {
  $err = '';
  $cfg  = staxx_cfg();
  $url  = rtrim((string)($cfg['NPM_URL']  ?? ''), '/');
  $user = (string)($cfg['NPM_USER'] ?? '');
  $pass = (string)($cfg['NPM_PASS'] ?? '');
  if ($url === '' || $user === '' || $pass === '') {
    $err = 'Nginx Proxy Manager is not set up yet — fill in its address, email and password in '
         . 'the Integrations settings.';
    return '';
  }

  [, $json] = staxx_expose_http(
    'POST', $url.'/api/tokens', ['identity' => $user, 'secret' => $pass], [], $err
  );
  if ($err !== '') return '';

  $token = is_array($json) ? (string)($json['token'] ?? '') : '';
  if ($token === '') {
    $err = 'Nginx Proxy Manager did not accept that email and password. Check them in the '
         . 'Integrations settings.';
    return '';
  }
  return $token;
}

/**
 * Nginx Proxy Manager's own version, from the same unauthenticated endpoint
 * phase 0 measured answering without a token (GET /api/).
 */
function staxx_npm_version(string $url, string &$err): string {
  $err = '';
  [, $json] = staxx_expose_http('GET', rtrim($url, '/').'/api/', null, [], $err);
  if ($err !== '') return '';
  $v = is_array($json) ? (array)($json['version'] ?? []) : [];
  if (!isset($v['major'])) {
    $err = 'Nginx Proxy Manager did not answer with a version number.';
    return '';
  }
  return $v['major'].'.'.($v['minor'] ?? 0).'.'.($v['revision'] ?? 0);
}

/**
 * Every certificate Nginx Proxy Manager holds — id, nice name and the domain
 * names it actually covers. Certificates are matched by nice_name elsewhere
 * (see the plan's *Where it would live in StaXX*: the numeric id means
 * nothing outside this one NPM), so id is carried along only so a caller can
 * resolve a chosen name back to what NPM needs on the wire.
 *
 * @return array<int, array{id:int, nice_name:string, domain_names:string[]}>
 */
function staxx_npm_certificates(string $url, string $token, string &$err): array {
  $err = '';
  [, $json] = staxx_expose_http(
    'GET', rtrim($url, '/').'/api/nginx/certificates', null, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return [];
  if (!is_array($json)) {
    $err = 'Nginx Proxy Manager did not answer with a certificate list.';
    return [];
  }
  $out = [];
  foreach ($json as $c) {
    if (!is_array($c)) continue;
    $out[] = [
      'id'           => (int)($c['id'] ?? 0),
      'nice_name'    => (string)($c['nice_name'] ?? ''),
      'domain_names' => array_map('strval', (array)($c['domain_names'] ?? [])),
    ];
  }
  return $out;
}

/**
 * Sign in to Pi-hole 6's API and return the headers a later call needs — an
 * empty array when none are needed at all.
 *
 * Phase 0 (2026-09-24) found Adrian's own Pi-hole has no admin password set,
 * and that Pi-hole answers such a login `valid:true, sid:null`: "no login
 * needed", not a failure. StaXX proceeds without a session header rather
 * than refusing, because that Pi-hole already accepts unauthenticated
 * changes from anyone on the network — refusing here would protect nothing
 * and only stop StaXX working. staxx_pihole_logout() must never be called
 * for the [] case: no session was opened, and Pi-hole's session pool is
 * small enough that only a real one should ever be closed.
 *
 * @return string[] headers to send on later calls, e.g. ['X-FTL-SID: …']
 */
/**
 * Pi-hole's address as the API wants it. People paste the address of the
 * admin page they use every day, which ends in /admin, and the API lives
 * beside that page rather than under it.
 */
function staxx_pihole_url(): string {
  $cfg = staxx_cfg();
  return preg_replace('#/admin$#i', '', rtrim((string)($cfg['PIHOLE_URL'] ?? ''), '/'));
}

function staxx_pihole_login(string &$err): array {
  $err = '';
  $url = staxx_pihole_url();
  if ($url === '') {
    $err = 'Pi-hole is not set up yet — fill in its address in the Integrations settings.';
    return [];
  }
  // Bug found while building B4: $cfg was never read here, so this always
  // sent a blank password. Harmless on Adrian's own Pi-hole (which accepts
  // one anyway — see the function comment above), but wrong on any Pi-hole
  // that actually has a password set.
  $cfg  = staxx_cfg();
  $pass = (string)($cfg['PIHOLE_PASS'] ?? '');

  [, $json] = staxx_expose_http('POST', $url.'/api/auth', ['password' => $pass], [], $err);
  if ($err !== '') return [];

  $session = is_array($json) ? (array)($json['session'] ?? $json) : [];
  if (empty($session['valid'])) {
    $err = 'Pi-hole did not accept that app password. Make a new one in Pi-hole\'s Settings → '
         . 'Web interface / API → Enable new app password, then paste it into the Integrations '
         . 'settings.';
    return [];
  }

  $sid = (string)($session['sid'] ?? '');
  return $sid === '' ? [] : ['X-FTL-SID: '.$sid];
}

/**
 * Ends a Pi-hole session. A no-op for the no-password case (see
 * staxx_pihole_login()) — calling this with [] would mean asking Pi-hole to
 * close a session that was never opened.
 */
function staxx_pihole_logout(string $url, array $headers): void {
  if ($headers === []) return;
  $err = '';
  staxx_expose_http('DELETE', rtrim($url, '/').'/api/auth', null, $headers, $err);
}

/**
 * Pi-hole's own version string (e.g. "v6.4.3"), from /api/info/version.
 * Callers refuse anything that does not start "v6" or "6" — Pi-hole 5 has no
 * supported API for local DNS (Adrian, 2026-09-24: v6 only).
 */
function staxx_pihole_version(string $url, array $headers, string &$err): string {
  $err = '';
  [, $json] = staxx_expose_http('GET', rtrim($url, '/').'/api/info/version', null, $headers, $err);
  if ($err !== '') return '';
  $v = is_array($json) ? (string)($json['version']['core']['local']['version'] ?? '') : '';
  if ($v === '') {
    $err = 'Pi-hole did not answer with a version number.';
    return '';
  }
  return $v;
}

/** True for any "v6…" or "6…" version string staxx_pihole_version() returns. */
function staxx_pihole_is_v6(string $version): bool {
  return preg_match('/^v?6(\.|$)/', $version) === 1;
}

/* ==================================================================== B4 ==
 * PLAN_176 build plan B4 — check and apply. Everything below reads a
 * service's `x-unraid.expose` block, decides what NPM and Pi-hole should
 * hold for it, and (only for expose-apply) makes it so. See the plan's
 * "Entries that already exist" section — that text plus this comment block
 * together are the spec these functions follow.
 * ========================================================================
 */

/** Where a stack's last-applied expose record lives — see Record.php's own
 * header for why nothing here may ever be required to exist. */
function staxx_expose_json_path(string $rel): string {
  return staxx_record_dir($rel).'/expose.json';
}

/**
 * Per service: {domain, npm_id, dns_ip} as last applied. Best-effort like
 * every other file under .staxx — a missing or hand-mangled file reads as
 * "nothing applied yet", never an error.
 */
function staxx_expose_json_read(string $rel): array {
  $raw  = @file_get_contents(staxx_expose_json_path($rel));
  $data = $raw !== false ? json_decode($raw, true) : null;
  return is_array($data) ? $data : [];
}

/** Same rename-into-place pattern as staxx_update_state_save() (Updates.php). */
function staxx_expose_json_write(string $rel, array $data): bool {
  $dir = staxx_record_dir($rel);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) return false;
  $file = staxx_expose_json_path($rel);
  $tmp  = $dir.'/.expose.json.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $encoded) === false) return false;
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}

/** Replaces one service's entry and writes the whole file back. */
function staxx_expose_json_update(string $rel, string $service, array $record): void {
  $all = staxx_expose_json_read($rel);
  $all[$service] = $record;
  staxx_expose_json_write($rel, $all);
}

/**
 * A service's x-unraid.expose block, read from staxx_compose_meta()'s own
 * flattened x['expose.*'] keys (Stacks.php's flattener widens one dotted
 * level under x-unraid, which is exactly the shape `expose:` arrives in —
 * `services.<name>.x-unraid.expose.domain` lands as x['expose.domain']).
 * Returns null when the service has no domain at all: a domain is what
 * makes a proxy host exist at all (Proposed behaviour 3), so every other
 * field is meaningless without one.
 */
function staxx_expose_config(array $svcX): ?array {
  $domain = trim((string)($svcX['expose.domain'] ?? ''));
  if ($domain === '') return null;
  // The compose reader hands scalars over as the text written in the file, so
  // `false` arrives as the string "false", which a plain (bool) cast reads as
  // true. Only the YAML spellings of false count as off.
  $flag = function (string $key, bool $absent) use ($svcX): bool {
    if (!array_key_exists($key, $svcX)) return $absent;
    $v = $svcX[$key];
    if (is_bool($v)) return $v;
    return !in_array(strtolower(trim((string)$v)), ['false', 'no', 'off', '0', ''], true);
  };
  return [
    'domain'      => $domain,
    'certificate' => isset($svcX['expose.certificate']) ? trim((string)$svcX['expose.certificate']) : '',
    'dns'         => $flag('expose.dns', false),
    'websockets'  => $flag('expose.websockets', true),
    'enabled'     => $flag('expose.enabled', true),
  ];
}

/**
 * Every service across every stack that currently has expose.domain set —
 * read straight off each compose file, not from any expose.json, because a
 * domain someone is about to save has to be caught as a clash before it is
 * ever applied (Entries that already exist: "Several services sharing one
 * domain within StaXX: refused at check time").
 *
 * @return array<string, array<string, string>> rel => service => domain
 */
function staxx_expose_all_configs(): array {
  $out = [];
  foreach (staxx_list_stacks() as $stack) {
    if ($stack['file'] === '') continue;
    $meta = staxx_compose_meta($stack['file']);
    foreach ($meta['services'] as $svcName => $svc) {
      $config = staxx_expose_config($svc['x']);
      if ($config !== null) $out[$stack['name']][$svcName] = $config['domain'];
    }
  }
  return $out;
}

/**
 * The forward target for one service, from the same resolver the row's own
 * web-page link uses (staxx_webui_for(), Stacks.php) — so "what the proxy
 * forwards to" and "what the web-page icon opens" can never quietly
 * disagree. Its own refusal sentences (no compose file, container not
 * running, no port) are used as-is since they say more than the plan's
 * generic sentence would; that sentence is only the fallback for the one
 * case staxx_webui_for() could somehow return '' with no $err set.
 */
function staxx_expose_target(string $rel, string $service, string &$err): ?array {
  $err = '';
  $url = staxx_webui_for($rel, $service, $err);
  if ($url === '') {
    if ($err === '') $err = 'Give this app a web page address first; the proxy forwards to it.';
    return null;
  }
  $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
  $host   = (string)parse_url($url, PHP_URL_HOST);
  $port   = parse_url($url, PHP_URL_PORT);
  if ($host === '' || $port === null) {
    $err = 'Give this app a web page address first; the proxy forwards to it.';
    return null;
  }
  return ['scheme' => $scheme !== '' ? $scheme : 'http', 'host' => $host, 'port' => (int)$port];
}

/** Every proxy host NPM holds, exactly as NPM sent it — StaXX only ever
 * reads the id, domain_names, meta and the fields it owns from these. */
function staxx_npm_proxy_hosts(string $url, string $token, string &$err): array {
  $err = '';
  [, $json] = staxx_expose_http(
    'GET', rtrim($url, '/').'/api/nginx/proxy-hosts', null, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return [];
  if (!is_array($json)) {
    $err = 'Nginx Proxy Manager did not answer with a list of proxy hosts.';
    return [];
  }
  return $json;
}

/**
 * Resolve a certificate name to NPM's numeric id and confirm it actually
 * covers the domain. A `*.x` wildcard covers exactly one label under `x`
 * (the plan's own definition), never a deeper subdomain. Returns 0 (plain
 * http) for a blank name.
 */
function staxx_npm_cert_resolve(array $certs, string $name, string $domain, string &$err): int {
  $err = '';
  if ($name === '') return 0;

  $matches = array_values(array_filter($certs, fn($c) => $c['nice_name'] === $name));
  if (count($matches) === 0) {
    $err = 'No certificate named "'.$name.'" was found in Nginx Proxy Manager.';
    return 0;
  }
  if (count($matches) > 1) {
    $err = 'More than one certificate in Nginx Proxy Manager is named "'.$name.'". '
         . 'Give them different names there first.';
    return 0;
  }

  $cert   = $matches[0];
  $covers = false;
  foreach ($cert['domain_names'] as $d) {
    if (strcasecmp($d, $domain) === 0) { $covers = true; break; }
    if (strpos($d, '*.') === 0) {
      $base = substr($d, 2);
      if (preg_match('/^[^.]+\.'.preg_quote($base, '/').'$/i', $domain)) { $covers = true; break; }
    }
  }
  if (!$covers) {
    $err = 'The certificate "'.$name.'" does not cover '.$domain.'.';
    return 0;
  }
  return $cert['id'];
}

/** A certificate's display name for a diff line, given the id NPM stored. */
function staxx_npm_cert_name(array $certs, int $id): string {
  if ($id <= 0) return 'none (plain http)';
  foreach ($certs as $c) if ($c['id'] === $id) return $c['nice_name'];
  return '#'.$id;
}

/**
 * The fields StaXX owns and sends, always merging the host's own current
 * `meta` rather than replacing it — phase 0 (2026-09-24) measured NPM
 * replacing `meta` wholesale on a PUT, dropping any hand-set key that was
 * not re-sent, so the caller must pass the host's own current meta in.
 * `$forCreate` adds the fields a create needs a value for but an update
 * must never touch (access_list_id, caching, advanced_config, locations,
 * hsts) — see "Entries that already exist", the Plex test case.
 */
function staxx_npm_host_payload(array $target, array $config, int $certId, array $currentMeta, string $staxxMeta, bool $forCreate): array {
  $hasCert = $certId > 0;
  $meta = $currentMeta;
  $meta['staxx'] = $staxxMeta;

  $payload = [
    'domain_names'            => [$config['domain']],
    'forward_scheme'          => $target['scheme'],
    'forward_host'            => $target['host'],
    'forward_port'            => $target['port'],
    'certificate_id'          => $certId,
    'ssl_forced'               => $hasCert,
    'http2_support'            => $hasCert,
    'block_exploits'           => true,
    'allow_websocket_upgrade'  => $config['websockets'],
    'meta'                     => $meta,
  ];
  if ($forCreate) {
    $payload += [
      'access_list_id'  => 0,
      'caching_enabled' => false,
      'advanced_config' => '',
      'locations'       => [],
      'hsts_enabled'    => false,
      'hsts_subdomains' => false,
    ];
  }
  return $payload;
}

function staxx_npm_create_host(string $url, string $token, array $payload, string &$err): ?array {
  $err = '';
  [$code, $json] = staxx_expose_http(
    'POST', rtrim($url, '/').'/api/nginx/proxy-hosts', $payload, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return null;
  if ($code >= 300 || !is_array($json) || !isset($json['id'])) {
    $err = 'Nginx Proxy Manager refused to create the proxy entry'
         . (is_array($json) && isset($json['error']['message']) ? ': '.$json['error']['message'].'.' : '.');
    return null;
  }
  return $json;
}

function staxx_npm_update_host(string $url, string $token, int $id, array $payload, string &$err): ?array {
  $err = '';
  [$code, $json] = staxx_expose_http(
    'PUT', rtrim($url, '/').'/api/nginx/proxy-hosts/'.$id, $payload, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return null;
  if ($code >= 300 || !is_array($json)) {
    $err = 'Nginx Proxy Manager refused to update the proxy entry'
         . (is_array($json) && isset($json['error']['message']) ? ': '.$json['error']['message'].'.' : '.');
    return null;
  }
  return $json;
}

function staxx_npm_set_enabled(string $url, string $token, int $id, bool $enabled, string &$err): bool {
  $err = '';
  [$code, ] = staxx_expose_http(
    'POST', rtrim($url, '/').'/api/nginx/proxy-hosts/'.$id.'/'.($enabled ? 'enable' : 'disable'),
    null, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return false;
  if ($code >= 300) {
    $err = 'Nginx Proxy Manager refused to switch that proxy entry '.($enabled ? 'on' : 'off').'.';
    return false;
  }
  return true;
}

function staxx_npm_delete_host(string $url, string $token, int $id, string &$err): bool {
  $err = '';
  [$code, ] = staxx_expose_http(
    'DELETE', rtrim($url, '/').'/api/nginx/proxy-hosts/'.$id, null, ['Authorization: Bearer '.$token], $err
  );
  if ($err !== '') return false;
  if ($code >= 300 && $code !== 404) {
    $err = 'Nginx Proxy Manager refused to delete that proxy entry.';
    return false;
  }
  return true;
}

/** Every Pi-hole local DNS record, parsed from its "ip name" strings. */
function staxx_pihole_hosts(string $url, array $headers, string &$err): array {
  $err = '';
  [, $json] = staxx_expose_http('GET', rtrim($url, '/').'/api/config/dns/hosts', null, $headers, $err);
  if ($err !== '') return [];
  $raw = is_array($json) ? (array)($json['config']['dns']['hosts'] ?? []) : [];
  $out = [];
  foreach ($raw as $line) {
    if (!is_string($line)) continue;
    $sp = strpos($line, ' ');
    if ($sp === false) continue;
    $out[] = ['ip' => substr($line, 0, $sp), 'name' => substr($line, $sp + 1)];
  }
  return $out;
}

function staxx_pihole_find(array $hosts, string $name): ?array {
  foreach ($hosts as $h) if (strcasecmp($h['name'], $name) === 0) return $h;
  return null;
}

function staxx_pihole_add(string $url, array $headers, string $ip, string $name, string &$err): bool {
  $err  = '';
  $path = '/api/config/dns/hosts/'.rawurlencode($ip.' '.$name);
  [$code, ] = staxx_expose_http('PUT', rtrim($url, '/').$path, null, $headers, $err);
  if ($err !== '') return false;
  if ($code >= 300) {
    $err = 'Pi-hole refused to add that DNS name.'.staxx_pihole_sudo_hint($code);
    return false;
  }
  return true;
}

function staxx_pihole_delete(string $url, array $headers, string $ip, string $name, string &$err): bool {
  $err  = '';
  $path = '/api/config/dns/hosts/'.rawurlencode($ip.' '.$name);
  [$code, ] = staxx_expose_http('DELETE', rtrim($url, '/').$path, null, $headers, $err);
  if ($err !== '') return false;
  if ($code >= 300 && $code !== 404) {
    $err = 'Pi-hole refused to remove that DNS name.'.staxx_pihole_sudo_hint($code);
    return false;
  }
  return true;
}

/** Reads Pi-hole's own list straight back and confirms the record just added
 * is really there, at the address it was just given. Phase 0 only measured
 * a successful add reading back correctly; it never measured a call that
 * returns success but silently does nothing, so expose-apply checks this
 * explicitly rather than trusting a 20x/201 status alone. */
function staxx_pihole_confirm(string $url, array $headers, string $domain, string $ip, string &$err): bool {
  $err = '';
  $fresh = staxx_pihole_hosts($url, $headers, $err);
  if ($err !== '') return false;
  $rec = staxx_pihole_find($fresh, $domain);
  if ($rec === null || $rec['ip'] !== $ip) {
    $err = "Pi-hole accepted the DNS name but does not list it. Check Pi-hole's Local DNS page.";
    return false;
  }
  return true;
}

/** Phase 0 left unproven whether a password-protected Pi-hole needs "app
 * sudo" turned on before an app password can change DNS settings — this
 * turns a refusal that looks like it (403, or Pi-hole's own permission
 * codes) into the one sentence the plan asks for, as a possibility rather
 * than a diagnosis. */
function staxx_pihole_sudo_hint(int $code): string {
  if ($code !== 403) return '';
  return ' If Pi-hole has an admin password, turn on "Permit destructive actions via API" '
       . '(app sudo) in Settings → Web interface / API (expert mode), then try again.';
}

/**
 * Where the DNS record should point — Nginx Proxy Manager's own address
 * (Proposed behaviour 3: the record always points at NPM, never straight at
 * the app, because a DNS record carries no port). A host name is resolved
 * with gethostbyname(); an address already given as an IP is used as-is.
 */
function staxx_expose_dns_ip(string &$err): string {
  $err  = '';
  $cfg  = staxx_cfg();
  $host = (string)parse_url(rtrim((string)($cfg['NPM_URL'] ?? ''), '/'), PHP_URL_HOST);
  if ($host === '') {
    $err = 'Nginx Proxy Manager\'s address is not set, so StaXX has no address to point the DNS name at.';
    return '';
  }
  if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
  $ip = @gethostbyname($host);
  if ($ip === '' || $ip === $host) {
    $err = 'Could not look up an address for '.$host.'.';
    return '';
  }
  return $ip;
}

/** The owned-field differences between an existing NPM host and what StaXX
 * would write, as the plan's own sub-line sentences. */
function staxx_npm_diff(array $host, array $target, int $certId, array $config, array $certs): array {
  $diffs = [];

  $oldHostPort = ($host['forward_host'] ?? '').':'.($host['forward_port'] ?? '');
  $newHostPort = $target['host'].':'.$target['port'];
  if ($oldHostPort !== $newHostPort || (string)($host['forward_scheme'] ?? '') !== $target['scheme']) {
    $diffs[] = 'forwards to '.$oldHostPort.', will become '.$newHostPort;
  }

  $oldCertId = (int)($host['certificate_id'] ?? 0);
  if ($oldCertId !== $certId) {
    $diffs[] = 'certificate '.staxx_npm_cert_name($certs, $oldCertId)
             . ', will become '.staxx_npm_cert_name($certs, $certId);
  }

  $oldWs = !empty($host['allow_websocket_upgrade']);
  if ($oldWs !== $config['websockets']) {
    $diffs[] = 'WebSockets '.($oldWs ? 'on' : 'off').', will become '.($config['websockets'] ? 'on' : 'off');
  }

  return $diffs;
}

/**
 * The plan of steps for one service — what expose-check shows and what
 * expose-apply carries out, built from data the caller fetched once for the
 * whole stack (or whole list, for expose-status) rather than once per
 * service. Returns the step list, or sets $err to a per-service refusal
 * (a multi-domain NPM host, a taken certificate, a domain already used by
 * another StaXX service) that must not stop any other service being
 * checked.
 */
function staxx_expose_plan_service(
  string $rel, string $service, array $config, array $target,
  array $npmHosts, array $npmCerts, array $piHosts, string $dnsIp,
  array $allConfigs, string &$err
): array {
  $err = '';
  $staxxMeta = $rel.'/'.$service;

  foreach ($allConfigs as $otherRel => $svcs) {
    foreach ($svcs as $otherSvc => $domain) {
      if ($otherRel === $rel && $otherSvc === $service) continue;
      if (strcasecmp($domain, $config['domain']) === 0) {
        $err = $config['domain'].' is already used by '.$otherRel.'/'.$otherSvc.'.';
        return [];
      }
    }
  }

  $certId = 0;
  if ($config['certificate'] !== '') {
    $certErr = '';
    $certId  = staxx_npm_cert_resolve($npmCerts, $config['certificate'], $config['domain'], $certErr);
    if ($certErr !== '') { $err = $certErr; return []; }
  }

  $host = null;
  foreach ($npmHosts as $h) {
    if (in_array($config['domain'], (array)($h['domain_names'] ?? []), true)) { $host = $h; break; }
  }

  $steps = [];

  if ($host !== null && count((array)($host['domain_names'] ?? [])) > 1) {
    $others = array_diff((array)$host['domain_names'], [$config['domain']]);
    $err = 'The proxy entry for '.$config['domain'].' also serves '.implode(', ', $others)
         . '. Split it in Nginx Proxy Manager first, or leave this app out.';
    return [];
  }

  if ($host === null && !$config['enabled']) {
    // Switched off with no entry yet: there is nothing to switch off, and
    // creating one only to disable it would put an entry in NPM nobody asked for.
    $steps[] = ['service' => $service, 'target' => 'npm', 'op' => 'none',
                'text' => 'Switched off, so there is no proxy entry for '.$config['domain'].'.'];
  } elseif ($host === null) {
    $steps[] = [
      'service' => $service, 'target' => 'npm', 'op' => 'create',
      'text' => 'Create a proxy entry for '.$config['domain'].', forwarding to '
              . $target['scheme'].'://'.$target['host'].':'.$target['port']
              . ($config['certificate'] !== '' ? ', with the '.$config['certificate'].' certificate.' : '.'),
    ];
  } else {
    $mine        = (($host['meta']['staxx'] ?? '') === $staxxMeta);
    $diffs       = staxx_npm_diff($host, $target, $certId, $config, $npmCerts);
    $wantEnabled = $config['enabled'];
    $isEnabled   = !empty($host['enabled']);

    if (!$mine) {
      $text = 'Take over the existing proxy entry for '.$config['domain'].'.';
      $steps[] = [
        'service' => $service, 'target' => 'npm', 'op' => 'adopt',
        'text'    => $diffs === [] ? $text.' Nothing about it changes.' : $text,
        'diffs'   => $diffs,
      ];
    } elseif (!$wantEnabled && $isEnabled) {
      $steps[] = ['service' => $service, 'target' => 'npm', 'op' => 'disable',
                  'text' => 'Switch off the proxy entry for '.$config['domain'].'.'];
    } elseif ($wantEnabled && !$isEnabled) {
      $steps[] = ['service' => $service, 'target' => 'npm', 'op' => 'enable',
                  'text' => 'Switch the proxy entry for '.$config['domain'].' back on.'];
    } elseif ($diffs !== []) {
      $steps[] = ['service' => $service, 'target' => 'npm', 'op' => 'update',
                  'text' => 'Update the proxy entry for '.$config['domain'].'.', 'diffs' => $diffs];
    } else {
      $steps[] = ['service' => $service, 'target' => 'npm', 'op' => 'none',
                  'text' => 'The proxy entry for '.$config['domain'].' matches.'];
    }
  }

  if ($config['dns']) {
    $rec = staxx_pihole_find($piHosts, $config['domain']);
    if ($rec === null) {
      $steps[] = ['service' => $service, 'target' => 'pihole', 'op' => 'create',
                  'text' => 'Add a Pi-hole DNS name for '.$config['domain'].', pointing at '.$dnsIp.'.'];
    } elseif ($rec['ip'] === $dnsIp) {
      // "Adopted with no question" (Entries that already exist) — already
      // exactly what StaXX would write, so 'none' rather than a confirm line.
      $steps[] = ['service' => $service, 'target' => 'pihole', 'op' => 'none',
                  'text' => 'The Pi-hole DNS name '.$config['domain'].' matches.'];
    } else {
      $steps[] = ['service' => $service, 'target' => 'pihole', 'op' => 'replace',
                  'text' => 'Pi-hole sends '.$config['domain'].' to '.$rec['ip']
                          . '; this will change it to Nginx Proxy Manager.'];
    }
  }

  return $steps;
}

/** Carries out one step from staxx_expose_plan_service(), and updates
 * $record (this service's expose.json entry) in place on success. */
function staxx_expose_apply_step(
  string $rel, string $service, array $step, array $config, array $target,
  array $npmCerts, array $npmHosts, string $npmUrl, string $npmToken,
  string $piUrl, array $piHeaders, string $dnsIp, array &$record, string &$err
): bool {
  $err = '';
  $staxxMeta = $rel.'/'.$service;

  if ($step['target'] === 'npm') {
    if ($step['op'] === 'none') { $record['domain'] = $config['domain']; return true; }

    $certErr = '';
    $certId  = $config['certificate'] !== ''
      ? staxx_npm_cert_resolve($npmCerts, $config['certificate'], $config['domain'], $certErr) : 0;
    if ($certErr !== '') { $err = $certErr; return false; }

    if ($step['op'] === 'create') {
      $payload = staxx_npm_host_payload($target, $config, $certId, [], $staxxMeta, true);
      $created = staxx_npm_create_host($npmUrl, $npmToken, $payload, $err);
      if ($created === null) return false;
      $record['domain']  = $config['domain'];
      $record['npm_id']  = (int)$created['id'];
      return true;
    }

    $host = null;
    foreach ($npmHosts as $h) {
      if (in_array($config['domain'], (array)($h['domain_names'] ?? []), true)) { $host = $h; break; }
    }
    if ($host === null) {
      $err = 'That proxy entry has disappeared from Nginx Proxy Manager since this was checked.';
      return false;
    }

    if ($step['op'] === 'update' || $step['op'] === 'adopt') {
      // meta is ALWAYS the host's current meta, read just before, plus
      // staxx — phase 0 measured a PUT replacing meta wholesale rather than
      // merging it, so sending anything less would drop a hand-set key
      // (the Plex test case's letsencrypt_agree/dns_challenge).
      $payload = staxx_npm_host_payload($target, $config, $certId, (array)($host['meta'] ?? []), $staxxMeta, false);
      $updated = staxx_npm_update_host($npmUrl, $npmToken, (int)$host['id'], $payload, $err);
      if ($updated === null) return false;
      $record['domain'] = $config['domain'];
      $record['npm_id'] = (int)$host['id'];
      return true;
    }

    if ($step['op'] === 'enable' || $step['op'] === 'disable') {
      if (!staxx_npm_set_enabled($npmUrl, $npmToken, (int)$host['id'], $step['op'] === 'enable', $err)) return false;
      $record['domain'] = $config['domain'];
      $record['npm_id'] = (int)$host['id'];
      return true;
    }
  }

  if ($step['target'] === 'pihole') {
    if ($step['op'] === 'none') { $record['dns_ip'] = $dnsIp; return true; }

    if ($step['op'] === 'create') {
      if (!staxx_pihole_add($piUrl, $piHeaders, $dnsIp, $config['domain'], $err)) return false;
      if (!staxx_pihole_confirm($piUrl, $piHeaders, $config['domain'], $dnsIp, $err)) return false;
      $record['dns_ip'] = $dnsIp;
      return true;
    }

    if ($step['op'] === 'replace') {
      $freshErr = '';
      $fresh = staxx_pihole_hosts($piUrl, $piHeaders, $freshErr);
      $old   = $freshErr === '' ? staxx_pihole_find($fresh, $config['domain']) : null;
      if ($old !== null) {
        $delErr = '';
        staxx_pihole_delete($piUrl, $piHeaders, $old['ip'], $config['domain'], $delErr); // best-effort; the add below still tries
      }
      if (!staxx_pihole_add($piUrl, $piHeaders, $dnsIp, $config['domain'], $err)) return false;
      if (!staxx_pihole_confirm($piUrl, $piHeaders, $config['domain'], $dnsIp, $err)) return false;
      $record['dns_ip'] = $dnsIp;
      return true;
    }
  }

  $err = 'Unknown step.';
  return false;
}

/**
 * The one NPM login and one Pi-hole session shared by expose-check and
 * expose-apply — $apply false only builds the plan, $apply true carries it
 * out step by step, service by service, right after building it, so what
 * was shown and what was written can never drift apart (Refusals: "never
 * reported as done unless a read-back... shows the entry as intended").
 *
 * @return array<string, array{steps?:array, refusal?:string}>
 */
function staxx_expose_run(string $rel, bool $apply, string &$err): array {
  $err = '';
  if (!staxx_valid_path($rel)) { $err = 'That stack name is not valid.'; return []; }
  $file = staxx_find_compose_file(staxx_stack_dir($rel));
  if ($file === '') { $err = 'No compose file was found for that stack.'; return []; }
  $meta = staxx_compose_meta($file);

  $configs = [];
  foreach ($meta['services'] as $svcName => $svc) {
    $c = staxx_expose_config($svc['x']);
    if ($c !== null) $configs[$svcName] = $c;
  }
  if ($configs === []) return [];

  $needsDns = false;
  foreach ($configs as $c) if ($c['dns']) $needsDns = true;

  $cfg    = staxx_cfg();
  $npmUrl = trim((string)($cfg['NPM_URL'] ?? ''));
  if ($npmUrl === '') {
    $err = 'Nginx Proxy Manager is not set up yet — fill in its address in the Integrations settings.';
    return [];
  }
  $npmErr = '';
  $token  = staxx_npm_login($npmErr);
  if ($token === '') { $err = $npmErr; return []; }
  $certs = staxx_npm_certificates($npmUrl, $token, $npmErr);
  if ($npmErr !== '') { $err = $npmErr; return []; }
  $hosts = staxx_npm_proxy_hosts($npmUrl, $token, $npmErr);
  if ($npmErr !== '') { $err = $npmErr; return []; }

  $piUrl = ''; $piHeaders = []; $piHosts = []; $dnsIp = '';
  if ($needsDns) {
    $piUrl = staxx_pihole_url();
    if ($piUrl === '') {
      $err = 'Pi-hole is not set up yet — fill in its address in the Integrations settings, or turn DNS off for this service.';
      return [];
    }
    $piErr = '';
    $piHeaders = staxx_pihole_login($piErr);
    if ($piErr !== '') { $err = $piErr; return []; }
    $piHosts = staxx_pihole_hosts($piUrl, $piHeaders, $piErr);
    if ($piErr === '') $dnsIp = staxx_expose_dns_ip($piErr);
    // A session was opened above, and Pi-hole's pool of them is small.
    if ($piErr !== '') { staxx_pihole_logout($piUrl, $piHeaders); $err = $piErr; return []; }
  }

  $allConfigs = staxx_expose_all_configs();
  $records    = staxx_expose_json_read($rel);
  $out = [];

  foreach ($configs as $svcName => $config) {
    $tErr = '';
    $target = staxx_expose_target($rel, $svcName, $tErr);
    if ($target === null) { $out[$svcName] = ['refusal' => $tErr]; continue; }

    $sErr = '';
    $steps = staxx_expose_plan_service(
      $rel, $svcName, $config, $target, $hosts, $certs, $piHosts, $dnsIp, $allConfigs, $sErr
    );
    if ($sErr !== '') { $out[$svcName] = ['refusal' => $sErr]; continue; }

    if (!$apply) { $out[$svcName] = ['steps' => $steps]; continue; }

    $applied = [];
    $record  = $records[$svcName] ?? [];
    foreach ($steps as $step) {
      $stepErr = '';
      $ok = staxx_expose_apply_step(
        $rel, $svcName, $step, $config, $target, $certs, $hosts,
        $npmUrl, $token, $piUrl, $piHeaders, $dnsIp, $record, $stepErr
      );
      $applied[] = $step + ['done' => $ok, 'error' => $stepErr];
      // Stop THIS service's own remaining steps on a failure (an NPM step
      // failing makes a following Pi-hole 'replace' meaningless), but other
      // services already queued still get their turn.
      if (!$ok) break;
    }
    staxx_expose_json_update($rel, $svcName, $record);
    $out[$svcName] = ['steps' => $applied];
  }

  if ($needsDns) staxx_pihole_logout($piUrl, $piHeaders);
  return $out;
}

/**
 * expose-status: every exposed service across every stack, with one NPM
 * login and one Pi-hole session for the whole list — drives the per-service
 * drift mark (B5), never the periodic refresh.
 */
function staxx_expose_status(string &$err): array {
  $err = '';
  $allConfigs = [];
  foreach (staxx_list_stacks() as $stack) {
    if ($stack['file'] === '') continue;
    $meta = staxx_compose_meta($stack['file']);
    foreach ($meta['services'] as $svcName => $svc) {
      $c = staxx_expose_config($svc['x']);
      if ($c !== null) $allConfigs[$stack['name']][$svcName] = $c;
    }
  }
  if ($allConfigs === []) return [];

  $cfg    = staxx_cfg();
  $npmUrl = trim((string)($cfg['NPM_URL'] ?? ''));
  $npmErr = '';
  $token  = $npmUrl !== '' ? staxx_npm_login($npmErr) : '';
  $certs  = $token !== '' ? staxx_npm_certificates($npmUrl, $token, $npmErr) : [];
  $hosts  = $token !== '' ? staxx_npm_proxy_hosts($npmUrl, $token, $npmErr) : [];

  $piUrl = staxx_pihole_url();
  $piErr = '';
  $piHeaders = $piUrl !== '' ? staxx_pihole_login($piErr) : [];
  $piHosts   = ($piUrl !== '' && $piErr === '') ? staxx_pihole_hosts($piUrl, $piHeaders, $piErr) : [];
  $dnsErr = '';
  $dnsIp  = $piUrl !== '' ? staxx_expose_dns_ip($dnsErr) : '';

  $out = [];
  foreach ($allConfigs as $rel => $svcs) {
    foreach ($svcs as $svcName => $config) {
      $npmState = null;
      if ($token !== '') {
        $host = null;
        foreach ($hosts as $h) {
          if (in_array($config['domain'], (array)($h['domain_names'] ?? []), true)) { $host = $h; break; }
        }
        if ($host !== null && (($host['meta']['staxx'] ?? '') === $rel.'/'.$svcName)) {
          $tErr = ''; $ce = '';
          $target = staxx_expose_target($rel, $svcName, $tErr);
          $certId = $config['certificate'] !== '' ? staxx_npm_cert_resolve($certs, $config['certificate'], $config['domain'], $ce) : 0;
          $diffs  = ($target !== null && $ce === '') ? staxx_npm_diff($host, $target, $certId, $config, $certs) : ['-'];
          $npmState = ($diffs === []) && (!empty($host['enabled']) === $config['enabled']);
        } else {
          $npmState = false;
        }
      }

      $dnsState = null;
      if ($config['dns']) {
        $dnsState = ($piUrl !== '' && $piErr === '')
          ? (($rec = staxx_pihole_find($piHosts, $config['domain'])) !== null && $rec['ip'] === $dnsIp)
          : null;
      }

      $out[] = [
        'stack' => $rel, 'service' => $svcName, 'domain' => $config['domain'],
        'npm' => $npmState, 'dns' => $dnsState, 'enabled' => $config['enabled'],
      ];
    }
  }

  if ($piUrl !== '' && $piErr === '') staxx_pihole_logout($piUrl, $piHeaders);
  return $out;
}

/**
 * expose-remove: switches off, deletes or leaves alone only what
 * expose.json says StaXX made for this stack (or, with $onlyService, one
 * service of it), and only once an NPM host is re-checked to still carry
 * this service's own meta.staxx — a hand-deleted-and-recreated host with
 * the same id is no longer StaXX's to touch.
 *
 * @param string $npmChoice 'disable' | 'delete' | 'keep'
 * @param string $dnsChoice 'delete' | 'keep'
 */
function staxx_expose_remove(string $rel, string $onlyService, string $npmChoice, string $dnsChoice, string &$err): array {
  $err = '';
  if (!staxx_valid_path($rel)) { $err = 'That stack name is not valid.'; return []; }

  $records = staxx_expose_json_read($rel);
  if ($records === []) return [];
  $targets = $onlyService !== ''
    ? (isset($records[$onlyService]) ? [$onlyService => $records[$onlyService]] : [])
    : $records;
  if ($targets === []) return [];

  $needNpm = false; $needDns = false;
  foreach ($targets as $rec) {
    if (($rec['npm_id'] ?? 0) > 0 && $npmChoice !== 'keep') $needNpm = true;
    if (($rec['dns_ip'] ?? '') !== '' && $dnsChoice !== 'keep') $needDns = true;
  }

  $cfg = staxx_cfg();
  $npmUrl = trim((string)($cfg['NPM_URL'] ?? ''));
  $token  = '';
  if ($needNpm) {
    $npmErr = '';
    $token  = staxx_npm_login($npmErr);
    if ($token === '') { $err = $npmErr; return []; }
  }
  $piUrl = ''; $piHeaders = [];
  if ($needDns) {
    $piUrl = staxx_pihole_url();
    $piErr = '';
    $piHeaders = staxx_pihole_login($piErr);
    if ($piErr !== '') { $err = $piErr; return []; }
  }

  $out = [];
  $remaining = staxx_expose_json_read($rel);

  foreach ($targets as $svcName => $rec) {
    $entry = ['service' => $svcName];
    $keptNpm = true; $keptDns = true;

    $npmId = (int)($rec['npm_id'] ?? 0);
    if ($npmId > 0 && $npmChoice !== 'keep') {
      $keptNpm = false;
      $getErr = '';
      [, $host] = staxx_expose_http(
        'GET', rtrim($npmUrl, '/').'/api/nginx/proxy-hosts/'.$npmId, null,
        ['Authorization: Bearer '.$token], $getErr
      );
      if (is_array($host) && (($host['meta']['staxx'] ?? '') === $rel.'/'.$svcName)) {
        $stepErr = '';
        $ok = $npmChoice === 'delete'
          ? staxx_npm_delete_host($npmUrl, $token, $npmId, $stepErr)
          : staxx_npm_set_enabled($npmUrl, $token, $npmId, false, $stepErr);
        $entry['npm'] = ['ok' => $ok, 'error' => $stepErr];
      } else {
        // No longer StaXX's (or already gone) — nothing to act on, but the
        // record is still dropped below since there is nothing left to track.
        $entry['npm'] = ['ok' => true, 'error' => ''];
      }
    } elseif ($npmId > 0) {
      $keptNpm = true; // 'keep' — settings stay in NPM, StaXX keeps tracking it
    }

    $dnsIp = (string)($rec['dns_ip'] ?? '');
    if ($dnsIp !== '' && $dnsChoice === 'delete') {
      $keptDns = false;
      $stepErr = '';
      $ok = staxx_pihole_delete($piUrl, $piHeaders, $dnsIp, $rec['domain'] ?? '', $stepErr);
      $entry['dns'] = ['ok' => $ok, 'error' => $stepErr];
    } elseif ($dnsIp !== '') {
      $keptDns = true; // 'keep'
    }

    if (!$keptNpm && !$keptDns) unset($remaining[$svcName]);
    else {
      if (!$keptNpm) unset($remaining[$svcName]['npm_id']);
      if (!$keptDns) unset($remaining[$svcName]['dns_ip']);
    }

    $out[$svcName] = $entry;
  }

  staxx_expose_json_write($rel, $remaining);
  if ($needDns) staxx_pihole_logout($piUrl, $piHeaders);
  return $out;
}
