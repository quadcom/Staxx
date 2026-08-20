<?php
/* staxx_webui_url() and staxx_service_net_kind() — resolving the address and
 * port a service's web page button opens, against every shape of
 * x-unraid.webui, every port arrangement, and every way the kind of network
 * can be known: a live container's driver, a live container's mode, the
 * file's own network_mode, or none of the above.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/webui.php root@<box>:/tmp/
 *     plink … "php /tmp/webui.php"
 *
 * Prints one line per case and exits non-zero on any failure. Both functions
 * are pure, so every case here builds a $service array by hand — nothing is
 * written to disk, nothing calls Docker, and no real stack is touched. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* Small helper so each case only states what differs from the common shape:
 * a webui string, a fixed address (or '' for none), a first port (or [] for
 * "publishes nothing"), and network_mode as written in the file (or '' for
 * "not set"). */
function svc(string $webui, string $fixedIp = '', array $firstPort = [], string $netMode = ''): array {
  return ['x' => ['webui' => $webui], 'fixedIp' => $fixedIp, 'firstPort' => $firstPort, 'netMode' => $netMode];
}

$hostIp = '10.0.0.5';
$fixed  = '10.77.0.20';
$port   = ['target' => '80', 'published' => '15114'];

/* ---------------------------------------------------- host vs fixed ip --- */

ok('both tokens, no fixed address: server IP and the published port',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', $port), $hostIp)
     === 'http://10.0.0.5:15114/');

// Behaviour change from before this network-aware rule: a fixed address alone
// no longer implies a non-bridge network. With no live driver, no live mode
// and no network_mode in the file, this is read as bridge — so the address
// is the one written in, but the PORT is still the server-side half of the
// mapping, because on an actual bridge that is the only side anyone outside
// the container can reach.
ok('fixed address alone (no live facts, no network_mode) reads as bridge: fixed address, published port',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', $fixed, $port), $hostIp)
     === 'http://10.77.0.20:15114/');

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

/* ----------------------------------------------------- host networking --- */

ok('network_mode: host, only a container-side port: server IP and that port',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', ['target' => '8096', 'published' => ''], 'host'), $hostIp)
     === 'http://10.0.0.5:8096/');

/* -------------------------------------------------------------- macvlan --- */

ok('live driver macvlan, no fixed address, container-side port only: live IP and that port',
   staxx_webui_url(svc('http://[IP]:[PORT:80]/', '', ['target' => '80', 'published' => '']), $hostIp,
                    '10.77.0.20', 'my_net', 'macvlan')
     === 'http://10.77.0.20:80/');

ok('live driver macvlan, both sides written: the inside one is read',
   staxx_webui_url(svc('http://[IP]:[PORT:80]/', '', ['target' => '80', 'published' => '8080']), $hostIp,
                    '10.77.0.20', 'my_net', 'macvlan')
     === 'http://10.77.0.20:80/');

ok('fixed address in the file + live driver macvlan: that address, target port',
   staxx_webui_url(svc('http://[IP]:[PORT:80]/', $fixed, $port), $hostIp, '', 'my_net', 'macvlan')
     === 'http://10.77.0.20:80/');

/* --------------------------------------------- bridge, nothing published -- */

ok('bridge with only a container-side port: no link — the server-side port is random',
   staxx_webui_url(svc('http://[IP]:[PORT:80]/', '', ['target' => '80', 'published' => '']), $hostIp) === '');

/* --------------------------------------------------- live IP == host IP --- */

ok('live IP equal to the host IP counts as no live address at all',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', $fixed, $port), $hostIp, $hostIp, 'bridge', 'bridge')
     === 'http://10.77.0.20:15114/');

ok('a bridge binding to loopback is not taken as the address to open',
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', $port), $hostIp, '127.0.0.1', 'bridge', 'bridge')
     === 'http://10.0.0.5:15114/');

/* ---------------------------------------------------- network_mode: none -- */

ok("network_mode: none reads as bridge — there is no link either way",
   staxx_webui_url(svc('http://[IP]:[PORT:8096]/', '', $port, 'none'), $hostIp)
     === 'http://10.0.0.5:15114/');

/* ---------------------------------------- staxx_service_net_kind() itself -- */

ok('driver beats mode beats the file',
   staxx_service_net_kind(svc('', '', [], 'host'), 'default', 'macvlan') === 'other');

ok('mode is used when there is no live driver',
   staxx_service_net_kind(svc('', '', [], 'host'), 'host', '') === 'host');

ok('the file is used when there is neither a live driver nor a live mode',
   staxx_service_net_kind(svc('', '', [], 'host')) === 'host');

ok('an unknown live mode reads as bridge, never as other',
   staxx_service_net_kind(svc('', '', [], ''), 'some_custom_network', '') === 'bridge');

ok('an unrecognised live driver reads as bridge',
   staxx_service_net_kind(svc('', '', [], ''), '', 'overlay') === 'bridge');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
