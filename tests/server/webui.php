<?php
/* staxx_webui_url() — resolving the address a service's web page button
 * opens, against every shape of x-unraid.webui and every port arrangement.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/webui.php root@<box>:/tmp/
 *     plink … "php /tmp/webui.php"
 *
 * Prints one line per case and exits non-zero on any failure. staxx_webui_url()
 * is a pure function of its two arguments, so every case here builds a
 * $service array by hand — nothing is written to disk, nothing calls Docker,
 * and no real stack is touched. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* Small helper so each case only states what differs from the common shape:
 * a webui string, a fixed address (or '' for none) and a first port (or []
 * for "publishes nothing"). */
function svc(string $webui, string $fixedIp = '', array $firstPort = []): array {
  return ['x' => ['webui' => $webui], 'fixedIp' => $fixedIp, 'firstPort' => $firstPort];
}

$hostIp = '10.0.0.5';
$fixed  = '10.77.0.20';
$port   = ['target' => '80', 'published' => '15114'];

/* ---------------------------------------------------- host vs fixed ip --- */

ok('both tokens, no fixed address: server IP and the published port',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', $port), $hostIp)
     === 'http://10.0.0.5:15114/');

ok('both tokens, with a fixed address: that address and the target port',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', $fixed, $port), $hostIp)
     === 'http://10.77.0.20:80/');

/* ------------------------------------------- the number is discarded ---- */

$a = staxx_webui_url(svc('http://[IP]:[PORT:9999]/', '', $port), $hostIp);
$b = staxx_webui_url(svc('http://[IP]:[PORT:1]/', '', $port), $hostIp);
ok('the number inside [PORT:nnn] is ignored — both resolve the same',
   $a === $b && $a === 'http://10.0.0.5:15114/', "got $a and $b");

/* --------------------------------------------------- nothing to open ---- */

ok('[PORT:] present but the service publishes nothing => empty',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', []), $hostIp) === '');

ok('[IP] present, no fixed address, empty host IP => empty',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', $port), '') === '');

/* ------------------------------------------------------- path and scheme -- */

ok('a webui with a path keeps the path',
   staxx_webui_url(svc('http://[IP]:[PORT:1]/admin', '', $port), $hostIp)
     === 'http://10.0.0.5:15114/admin');

ok('a webui with https:// keeps the scheme',
   staxx_webui_url(svc('https://[IP]:[PORT:1]/', '', $port), $hostIp)
     === 'https://10.0.0.5:15114/');

/* --------------------------------------------------------- no tokens ---- */

ok('a webui with no tokens at all is returned verbatim',
   staxx_webui_url(svc('http://example.com/admin', '', $port), $hostIp)
     === 'http://example.com/admin');

/* ------------------------------------------------------------- absent --- */

ok('no webui at all => empty',
   staxx_webui_url(['x' => [], 'fixedIp' => '', 'firstPort' => $port], $hostIp) === '');

ok('no x block at all => empty',
   staxx_webui_url(['fixedIp' => '', 'firstPort' => $port], $hostIp) === '');

/* --------------------------------------------------------- bad schemes -- */

ok('javascript: is refused',
   staxx_webui_url(svc('javascript:alert(1)', '', $port), $hostIp) === '');

ok('ftp:// is refused',
   staxx_webui_url(svc('ftp://host/', '', $port), $hostIp) === '');

ok('a webui whose tokens resolve but is not http(s) once substituted => empty',
   staxx_webui_url(svc('ftp://[IP]:[PORT:1]/', '', $port), $hostIp) === '');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
