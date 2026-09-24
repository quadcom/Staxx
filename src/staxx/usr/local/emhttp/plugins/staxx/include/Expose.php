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
