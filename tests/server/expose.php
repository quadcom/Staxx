<?php
/* PLAN_176 — Nginx Proxy Manager and Pi-hole, checked against the real
 * installed Expose.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine.
 *
 * Part (a), always run, needs one config key forced before php starts
 * (staxx_cfg() memoises on first read, so this cannot be done from inside
 * the script — same reasoning tests/server/detail.php gives for
 * IMAGE_LOOKUP). That key is EXPOSE_ALLOW_INSECURE, and it lives in the
 * STORE's own settings file, not the flash pointer file: PLAN_176's build
 * plan (B2) put NPM_URL/NPM_USER/NPM_PASS/PIHOLE_URL/PIHOLE_PASS/
 * EXPOSE_ALLOW_INSECURE through the same non-flash path HUB_TOKEN already
 * uses, so they land in <store>/config/staxx.cfg — on Adrian's own box,
 * /mnt/m2cache/appdata/staxx/config/staxx.cfg — never in
 * /boot/config/plugins/staxx/staxx.cfg. Forcing it to "no" makes the one
 * network-shaped case in part (a) (the http:// refusal) deterministic
 * regardless of what the real setting currently is; every other case in
 * part (a) is a pure function fed made-up arrays and touches neither the
 * store, the config, nor the network.
 *
 *     scp tests/server/expose.php unraid:/tmp/
 *     ssh unraid '
 *       CFG=/mnt/m2cache/appdata/staxx/config/staxx.cfg
 *       cp $CFG /tmp/expose-cfg.bak
 *       grep -q "^EXPOSE_ALLOW_INSECURE=" $CFG \
 *         && sed -i "s#^EXPOSE_ALLOW_INSECURE=.*#EXPOSE_ALLOW_INSECURE=\"no\"#" $CFG \
 *         || echo "EXPOSE_ALLOW_INSECURE=\"no\"" >> $CFG
 *       php /tmp/expose.php; RC=$?
 *       cp /tmp/expose-cfg.bak $CFG
 *       diff -q /tmp/expose-cfg.bak $CFG && echo CONFIG_IDENTICAL
 *       exit $RC
 *     '
 *
 * Part (b) is opt-in, behind STAXX_EXPOSE_LIVE=1, and talks to the real NPM
 * and Pi-hole already configured in that same store config (NPM_URL,
 * NPM_USER, NPM_PASS, PIHOLE_URL, PIHOLE_PASS). It never touches any host or
 * record but the ones it makes itself, under a throwaway domain
 * ("staxx-suite-<pid>.invalid") that cannot collide with anything real, and
 * every entry it creates is removed on every exit path via
 * register_shutdown_function — including a PHP fatal error partway through.
 * Run it WITHOUT seeding EXPOSE_ALLOW_INSECURE, so the real setting reaches
 * the services (a plain-http NPM needs it on); the http refusal case skips
 * itself in a live run:
 *
 *     ssh unraid 'STAXX_EXPOSE_LIVE=1 php /tmp/expose.php'
 *
 * PULLS NOTHING, STARTS NOTHING, NEVER TOUCHES A REAL STACK OR THE STORE'S
 * STACKS/ARCHIVES FOLDERS. Expose.php's own functions never read or write a
 * stack's compose file or record folder in this suite — plan_service and
 * apply_step are exercised directly against in-memory arrays, never through
 * staxx_expose_run()/staxx_expose_status(), which is the only pair of
 * functions here that would touch staxx_list_stacks(). STORE_ROOT is left
 * exactly as it is on this box; nothing here redirects it, because nothing
 * here needs the real stacks tree at all.
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Expose.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ============================================================ part (a) ===
 * Pure functions and one config-shaped refusal. No stack, no store, no
 * network call that could actually reach anything.
 * ========================================================================
 */

/* ------------------------------------------------- staxx_npm_cert_resolve */

$certs = [
  ['id' => 1, 'nice_name' => 'quadcom.ca',   'domain_names' => ['quadcom.ca']],
  ['id' => 2, 'nice_name' => '*.quadcom.ca', 'domain_names' => ['*.quadcom.ca']],
];

$err = '';
$id  = staxx_npm_cert_resolve($certs, 'quadcom.ca', 'quadcom.ca', $err);
ok('cert_resolve: an exact name match resolves', $id === 1 && $err === '', $err);

$err = '';
$id  = staxx_npm_cert_resolve($certs, '*.quadcom.ca', 'sonarr.quadcom.ca', $err);
ok('cert_resolve: a wildcard covers one label under it', $id === 2 && $err === '', $err);

$err = '';
$id  = staxx_npm_cert_resolve($certs, '*.quadcom.ca', 'a.b.quadcom.ca', $err);
ok('cert_resolve: *.x does not cover a.b.x', $id === 0 && strpos($err, 'does not cover') !== false, $err);

$err = '';
$id  = staxx_npm_cert_resolve($certs, 'nowhere.tld', 'app.nowhere.tld', $err);
ok('cert_resolve: no match is refused', $id === 0 && strpos($err, 'No certificate named') !== false, $err);

$dupCerts = [
  ['id' => 1, 'nice_name' => 'dup.tld', 'domain_names' => ['dup.tld']],
  ['id' => 2, 'nice_name' => 'dup.tld', 'domain_names' => ['dup.tld']],
];
$err = '';
$id  = staxx_npm_cert_resolve($dupCerts, 'dup.tld', 'dup.tld', $err);
ok('cert_resolve: two matches are refused', $id === 0 && strpos($err, 'More than one certificate') !== false, $err);

/* -------------------------------------------------- staxx_npm_host_payload */

$target = ['scheme' => 'http', 'host' => '192.168.1.5', 'port' => 8080];
$config = ['domain' => 'app.example.com', 'websockets' => true];

$create = staxx_npm_host_payload($target, $config, 0, [], 'Media/app', true);
ok('host_payload: create adds access_list_id', array_key_exists('access_list_id', $create));
ok('host_payload: create adds caching_enabled false', $create['caching_enabled'] === false);
ok('host_payload: create adds advanced_config empty', $create['advanced_config'] === '');
ok('host_payload: create adds an empty locations list', $create['locations'] === []);
ok('host_payload: create adds hsts_enabled false', $create['hsts_enabled'] === false);
ok('host_payload: create adds hsts_subdomains false', $create['hsts_subdomains'] === false);

$update = staxx_npm_host_payload($target, $config, 0, [], 'Media/app', false);
foreach (['hsts_enabled', 'hsts_subdomains', 'caching_enabled', 'advanced_config', 'locations', 'access_list_id'] as $k) {
  ok('host_payload: update never sends '.$k, !array_key_exists($k, $update));
}

$withMeta = staxx_npm_host_payload(
  $target, $config, 0, ['letsencrypt_agree' => true, 'dns_challenge' => false], 'Media/app', false
);
ok('host_payload: meta is the current meta plus staxx',
   $withMeta['meta'] === ['letsencrypt_agree' => true, 'dns_challenge' => false, 'staxx' => 'Media/app']);

$noCert = staxx_npm_host_payload($target, $config, 0, [], 'Media/app', false);
ok('host_payload: ssl_forced is off with no certificate', $noCert['ssl_forced'] === false);
ok('host_payload: http2_support is off with no certificate', $noCert['http2_support'] === false);

$withCert = staxx_npm_host_payload($target, $config, 5, [], 'Media/app', false);
ok('host_payload: ssl_forced is on with a certificate', $withCert['ssl_forced'] === true);
ok('host_payload: http2_support is on with a certificate', $withCert['http2_support'] === true);

/* --------------------------------------------- staxx_expose_plan_service --
 * Every case below is fed made-up NPM hosts and Pi-hole records — nothing
 * is read from or written to disk, and staxx_expose_plan_service() never
 * makes an HTTP call itself. */

$rel     = 'Media/zzexposesuite';
$service = 'sonarr';
$domCfg  = ['domain' => 'sonarr.tld', 'certificate' => '', 'dns' => false, 'websockets' => true, 'enabled' => true];
$target2 = ['scheme' => 'http', 'host' => '192.168.1.5', 'port' => 8989];
$dnsIp   = '192.168.1.2'; // NPM's own address, for the Pi-hole cases below

// create: nothing exists yet in either service.
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $domCfg, $target2, [], [], [], $dnsIp, [], $err);
ok('plan_service: create when no NPM host and no Pi-hole record exist',
   $err === '' && count($steps) === 1 && $steps[0]['target'] === 'npm' && $steps[0]['op'] === 'create', $err);

// adopt, no diffs: an existing single-domain host that already matches exactly.
$host = [
  'id' => 1, 'domain_names' => ['sonarr.tld'],
  'forward_scheme' => 'http', 'forward_host' => '192.168.1.5', 'forward_port' => 8989,
  'certificate_id' => 0, 'allow_websocket_upgrade' => true, 'enabled' => true,
  'meta' => ['other' => 'x'], // not StaXX's — no meta.staxx
];
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $domCfg, $target2, [$host], [], [], $dnsIp, [], $err);
ok('plan_service: adopt with no diffs says nothing changes',
   $err === '' && count($steps) === 1 && $steps[0]['op'] === 'adopt'
   && $steps[0]['text'] === 'Take over the existing proxy entry for sonarr.tld. Nothing about it changes.'
   && $steps[0]['diffs'] === [], $err);

// adopt, with a diff: the existing host forwards somewhere else.
$hostDiff = $host;
$hostDiff['forward_port'] = 1234;
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $domCfg, $target2, [$hostDiff], [], [], $dnsIp, [], $err);
ok('plan_service: adopt with a diff lists it and drops the "nothing changes" sentence',
   $err === '' && $steps[0]['op'] === 'adopt'
   && $steps[0]['text'] === 'Take over the existing proxy entry for sonarr.tld.'
   && $steps[0]['diffs'] !== [], $err);

// multi-domain host: refused outright, no steps at all.
$hostMulti = $host;
$hostMulti['domain_names'] = ['sonarr.tld', 'other.tld'];
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $domCfg, $target2, [$hostMulti], [], [], $dnsIp, [], $err);
ok('plan_service: a host serving several domains is refused, not adopted',
   $steps === [] && strpos($err, 'also serves other.tld') !== false
   && strpos($err, 'Split it in Nginx Proxy Manager') !== false, $err);

// the same domain already claimed by another StaXX service.
$allConfigs = ['Media/other' => ['radarr' => 'sonarr.tld']];
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $domCfg, $target2, [], [], [], $dnsIp, $allConfigs, $err);
ok('plan_service: a domain used by another StaXX service is refused',
   $steps === [] && $err === 'sonarr.tld is already used by Media/other/radarr.', $err);

// Pi-hole record already at NPM's address: adopted with no question ('none').
$dnsCfg = $domCfg;
$dnsCfg['dns'] = true;
$piMatch = [['ip' => $dnsIp, 'name' => 'sonarr.tld']];
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $dnsCfg, $target2, [], [], $piMatch, $dnsIp, [], $err);
$piStep = null;
foreach ($steps as $s) if ($s['target'] === 'pihole') $piStep = $s;
ok('plan_service: a Pi-hole record already at NPM\'s address is "none"',
   $piStep !== null && $piStep['op'] === 'none', $err);

// Pi-hole record at a different address: replace.
$piOther = [['ip' => '10.0.0.9', 'name' => 'sonarr.tld']];
$err = '';
$steps = staxx_expose_plan_service($rel, $service, $dnsCfg, $target2, [], [], $piOther, $dnsIp, [], $err);
$piStep = null;
foreach ($steps as $s) if ($s['target'] === 'pihole') $piStep = $s;
ok('plan_service: a Pi-hole record at another address is replaced',
   $piStep !== null && $piStep['op'] === 'replace'
   && strpos($piStep['text'], 'will change it to Nginx Proxy Manager') !== false, $err);

/* ---------------------------------------------------- staxx_expose_config */
// The compose reader hands scalars over as the file's own text, so the
// switches arrive as the strings "true" / "false", not booleans.
$c = staxx_expose_config(['expose.domain' => 'a.example.com', 'expose.enabled' => 'false',
                          'expose.websockets' => 'false', 'expose.dns' => 'true']);
ok('expose_config: the text "false" switches Enabled and WebSockets off',
   $c !== null && $c['enabled'] === false && $c['websockets'] === false, json_encode($c));
ok('expose_config: the text "true" switches DNS name on', $c !== null && $c['dns'] === true, json_encode($c));
$c = staxx_expose_config(['expose.domain' => 'a.example.com']);
ok('expose_config: absent Enabled and WebSockets mean on, absent DNS name means off',
   $c['enabled'] === true && $c['websockets'] === true && $c['dns'] === false, json_encode($c));
ok('expose_config: no domain means no proxy entry at all', staxx_expose_config(['expose.dns' => 'true']) === null);

/* ------------------------------------------------------ staxx_expose_http */
// The one network-shaped case that belongs in the always-run half: it never
// reaches curl at all, because the scheme is refused before the call is
// made. EXPOSE_ALLOW_INSECURE="no" was forced into the real store config
// before this process started (see the header) — staxx_cfg() memoises, so
// nothing inside this script could change that after the fact.

// A live run keeps the real setting instead: a plain-http NPM, like the one on
// Adrian's box, is only reachable with it on, so the refusal cannot be checked
// in the same process.
$cfgNow = staxx_cfg();
if (getenv('STAXX_EXPOSE_LIVE') === '1') {
  echo "SKIP   expose_http plain-http refusal — checked in the ordinary run, not the live one\n";
} elseif (($cfgNow['EXPOSE_ALLOW_INSECURE'] ?? '') !== 'no') {
  echo "FAIL   EXPOSE_ALLOW_INSECURE was not seeded to \"no\" before php started — see this file's header\n";
  exit(1);
} else {
  $err = '';
  [$code, $json] = staxx_expose_http('GET', 'http://example.invalid/', null, [], $err);
  ok('expose_http: plain http is refused while EXPOSE_ALLOW_INSECURE is "no"',
     $code === 0 && $json === null && strpos($err, 'Allow insecure connections') !== false, $err);
}

/* ============================================================ part (b) ===
 * Opt-in, live. Talks to whatever NPM and Pi-hole are already configured on
 * this box, using one throwaway domain, and removes everything it made on
 * every exit path.
 * ========================================================================
 */

if (getenv('STAXX_EXPOSE_LIVE') !== '1') {
  echo "SKIP   live NPM/Pi-hole cases — set STAXX_EXPOSE_LIVE=1 to run them\n";
} else {
  $domain = 'staxx-suite-'.getmypid().'.invalid';
  $piIp   = '10.254.254.254';
  $npmId  = null;   // set once a real host exists; cleared once it is deleted
  $piAdded = false; // true only while the Pi-hole record actually exists

  register_shutdown_function(function () use (&$npmId, &$piAdded, $domain, $piIp) {
    if ($npmId !== null) {
      $lerr = '';
      $tok  = staxx_npm_login($lerr);
      if ($tok !== '') {
        $cfg = staxx_cfg();
        $derr = '';
        staxx_npm_delete_host(rtrim((string)($cfg['NPM_URL'] ?? ''), '/'), $tok, $npmId, $derr);
      }
    }
    if ($piAdded) {
      $lerr = '';
      $headers = staxx_pihole_login($lerr);
      if ($lerr === '') {
        $url = staxx_pihole_url();
        $derr = '';
        staxx_pihole_delete($url, $headers, $piIp, $domain, $derr);
        staxx_pihole_logout($url, $headers);
      }
    }
  });

  $cfg    = staxx_cfg();
  $npmUrl = rtrim((string)($cfg['NPM_URL'] ?? ''), '/');
  if ($npmUrl === '' || (string)($cfg['NPM_USER'] ?? '') === '' || (string)($cfg['NPM_PASS'] ?? '') === '') {
    echo "SKIP   live NPM cases — NPM_URL/NPM_USER/NPM_PASS are not set in the real config\n";
  } else {
    $err = '';
    $token = staxx_npm_login($err);
    ok('live: NPM login succeeds', $token !== '', $err);

    if ($token !== '') {
      $liveTarget = ['scheme' => 'http', 'host' => '127.0.0.1', 'port' => 9];
      $liveConfig = ['domain' => $domain, 'websockets' => true];
      $staxxMeta  = 'expose-suite/probe';

      $payload = staxx_npm_host_payload($liveTarget, $liveConfig, 0, [], $staxxMeta, true);
      $payload['caching_enabled'] = true; // the hand-set field the later PUT must not disturb

      $err = '';
      $created = staxx_npm_create_host($npmUrl, $token, $payload, $err);
      ok('live: NPM create succeeds', $created !== null, $err);

      if ($created !== null) {
        $npmId = (int)$created['id'];
        ok('live: created host carries meta.staxx', ($created['meta']['staxx'] ?? '') === $staxxMeta);
        ok('live: created host forwards to the target given',
           ($created['forward_host'] ?? '') === '127.0.0.1' && (int)($created['forward_port'] ?? 0) === 9);
        ok('live: created host has no certificate, so ssl_forced/http2_support are off',
           empty($created['ssl_forced']) && empty($created['http2_support']));

        $err = '';
        [$readCode, $read] = staxx_expose_http(
          'GET', $npmUrl.'/api/nginx/proxy-hosts/'.$npmId, null, ['Authorization: Bearer '.$token], $err
        );
        ok('live: read-back finds the created host', $readCode === 200 && is_array($read), $err);
        ok('live: read-back still carries meta.staxx', is_array($read) && ($read['meta']['staxx'] ?? '') === $staxxMeta);
        ok('live: read-back keeps the hand-set caching_enabled', is_array($read) && !empty($read['caching_enabled']));

        // Update sending only the fields StaXX owns — caching_enabled is not
        // among them, so it must survive untouched (the Plex test case,
        // PLAN_176 "Entries that already exist").
        $updatePayload = staxx_npm_host_payload($liveTarget, $liveConfig, 0, (array)($read['meta'] ?? []), $staxxMeta, false);
        $err = '';
        $updated = staxx_npm_update_host($npmUrl, $token, $npmId, $updatePayload, $err);
        ok('live: NPM update succeeds', $updated !== null, $err);
        ok('live: update keeps the hand-set caching_enabled', is_array($updated) && !empty($updated['caching_enabled']));

        $err = '';
        $disabled = staxx_npm_set_enabled($npmUrl, $token, $npmId, false, $err);
        ok('live: NPM disable succeeds', $disabled, $err);

        $err = '';
        $enabled = staxx_npm_set_enabled($npmUrl, $token, $npmId, true, $err);
        ok('live: NPM enable succeeds', $enabled, $err);

        $err = '';
        $deleted = staxx_npm_delete_host($npmUrl, $token, $npmId, $err);
        ok('live: NPM delete succeeds', $deleted, $err);

        $err = '';
        [$gone, ] = staxx_expose_http(
          'GET', $npmUrl.'/api/nginx/proxy-hosts/'.$npmId, null, ['Authorization: Bearer '.$token], $err
        );
        ok('live: the deleted host now 404s', $gone === 404, 'code='.$gone);
        $npmId = null; // gone for real — nothing left for the shutdown cleanup to do
      }
    }
  }

  $piUrl = staxx_pihole_url();
  if ($piUrl === '') {
    echo "SKIP   live Pi-hole cases — PIHOLE_URL is not set in the real config\n";
  } else {
    $err = '';
    $piHeaders = staxx_pihole_login($err);
    ok('live: Pi-hole login (or its no-password equivalent) succeeds', $err === '', $err);

    if ($err === '') {
      $err = '';
      $added = staxx_pihole_add($piUrl, $piHeaders, $piIp, $domain, $err);
      ok('live: Pi-hole add succeeds', $added, $err);

      if ($added) {
        $piAdded = true;
        $err = '';
        $hosts = staxx_pihole_hosts($piUrl, $piHeaders, $err);
        $rec = staxx_pihole_find($hosts, $domain);
        ok('live: read-back shows the record present, at the address given',
           $rec !== null && $rec['ip'] === $piIp, $err);

        $err = '';
        $deleted = staxx_pihole_delete($piUrl, $piHeaders, $piIp, $domain, $err);
        ok('live: Pi-hole delete succeeds', $deleted, $err);
        $piAdded = false;

        $err = '';
        $hosts2 = staxx_pihole_hosts($piUrl, $piHeaders, $err);
        ok('live: read-back shows the record gone', staxx_pihole_find($hosts2, $domain) === null, $err);
      }

      staxx_pihole_logout($piUrl, $piHeaders);
    }
  }
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
