<?php
/* staxx_webui_url() and staxx_service_net_kind() — resolving the address and
 * port a service's web page button opens, against every shape of
 * x-unraid.webui, every port arrangement, and every way the kind of network
 * can be known: a live container's driver, a live container's mode, the
 * file's own network_mode, or none of the above.
 *
 * Also staxx_webui_for() and staxx_webui_try() — resolving one service's
 * address the same way "Test web page" does, and trying it over the network.
 * staxx_webui_for() reads the real stack root, so its cases here are kept to
 * refusals, which are what matter and what can be asserted safely on a live
 * server. staxx_webui_try() is asserted properly: it never touches disk.
 *
 * Also staxx_address_webui_override() — the only source of a port on the
 * Address column's row for a container that publishes nothing (the grid no
 * longer prints an unpublished container's declared ports at all, since
 * nothing outside can reach them): when the web button resolves a port, that
 * one port becomes the row's whole list, whatever the image itself declares
 * (it-tools: declares 8080, the app actually listens on 80 because of a PORT
 * environment variable). Exhaustive, since it is a pure function of its
 * arguments and touches neither disk nor Docker.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/webui.php root@<box>:/tmp/
 *     plink … "php /tmp/webui.php"
 *
 * Prints one line per case and exits non-zero on any failure. Most cases
 * build a $service array by hand and touch neither disk nor Docker; the
 * staxx_webui_for() refusals and the closing "for reading" listing are the
 * exceptions, and neither writes anything or makes an outbound request. */

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

// Behaviour change from before the literal-port rule: a webui with no
// [PORT:…] token used to be honoured verbatim regardless of whether it named
// a port. Now the address is the whole truth, so one with no port anywhere
// resolves to '' — there is nothing for the button to open.
ok('a webui with a literal port and no tokens is returned verbatim',
   staxx_webui_url(svc('http://example.com:9000/admin', '', $port), $hostIp)
     === 'http://example.com:9000/admin');

/* -------------------------------------------------- literal port wins --- */

ok('literal port in the address wins outright — the mapping is not consulted (StirlingPDF case)',
   staxx_webui_url(svc('http://[IP]:80/', '', ['target' => '8080', 'published' => '80']), $hostIp)
     === 'http://10.0.0.5:80/');

ok('literal port with a hard-coded host: unchanged, no mapping consulted',
   staxx_webui_url(svc('http://192.168.1.50:5000', '', $port), $hostIp)
     === 'http://192.168.1.50:5000');

ok('literal port on a macvlan service with a live address: address substituted, port taken as written',
   staxx_webui_url(svc('http://[IP]:80/', '', ['target' => '8080', 'published' => '']), $hostIp,
                    '10.77.0.20', 'my_net', 'macvlan')
     === 'http://10.77.0.20:80/');

ok('https:// with a literal port: the scheme\'s own // is not mistaken for a port separator',
   staxx_webui_url(svc('https://[IP]:8443/', '', $port), $hostIp)
     === 'https://10.0.0.5:8443/');

ok('a path holding a colon and digits does not fool the port detection',
   staxx_webui_url(svc('http://[IP]:80/admin:1/8080', '', $port), $hostIp)
     === 'http://10.0.0.5:80/admin:1/8080');

/* ------------------------------------------------------- no port at all -- */

ok('an address with no port anywhere => empty, even with a mapping to hand',
   staxx_webui_url(svc('http://[IP]/admin', '', $port), $hostIp) === '');

/* ------------------------------------------- [PORT:…] token still works --- */

// mazanoke: address says [PORT:80], mapping is 8686:80 — the token's own
// number would be wrong, so the published half of the mapping is what the
// link must use. This is the migration path that keeps an unedited file
// working; see staxx_webui_url()'s doc comment.
ok('token on a bridge service resolves to the published half of the mapping (mazanoke)',
   staxx_webui_url(svc('http://[IP]:[PORT:80]/', '', ['target' => '80', 'published' => '8686']), $hostIp)
     === 'http://10.0.0.5:8686/');

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

/* ------------------------------------------------------ staxx_webui_for() -- */

$err = '';
ok('a stack that does not exist is refused',
   staxx_webui_for('zzt1nosuchstack', 'web', $err) === '' && $err !== '', $err);

$stacks = staxx_list_stacks();
if (!$stacks) {
  echo "       (no stacks on this server — skipping the real-stack staxx_webui_for() case)\n";
} else {
  $one = $stacks[0];
  $err = '';
  ok('a service the compose file does not declare is refused',
     staxx_webui_for($one['name'], 'zzt1nosuchservice', $err) === '' && $err !== '', $err);
}

/* ------------------------------------------------------ staxx_webui_try() -- */

ok('a non-http string is refused without running anything',
   staxx_webui_try('not-a-url', $code) === false && $code === 0);

$start = microtime(true);
$tried = staxx_webui_try('http://127.0.0.1:1', $code);
$ms    = round((microtime(true) - $start) * 1000);
ok("nothing listening on that port => false, code 0, and it did not hang ({$ms}ms)",
   $tried === false && $code === 0);

/* ------------------------------------------ staxx_address_webui_override() -- */

// The Address column no longer prints an unpublished container's declared
// (EXPOSE'd) ports at all — nothing outside can reach them. This is the one
// door back in: the port the web button resolves becomes the row's whole
// port list, since a PORT-style environment variable can move the running
// application off whatever the image declares without Docker ever noticing.
// See the function's own doc comment.

function addrs(string $port): array {
  return [['ip' => '192.168.202.64', 'label' => '192.168.202.64', 'ports' => [$port]]];
}

ok('nothing published, declared 8080, button opens 80 => the list becomes 80 (it-tools)',
   staxx_address_webui_override(addrs('8080'), false, 'http://192.168.202.64:80/', ['8080'])
     === [['ip' => '192.168.202.64', 'label' => '192.168.202.64', 'ports' => ['80']]]);

ok('nothing published, declared 61208+61209, button opens 61208 => list becomes just 61208 (glances)',
   staxx_address_webui_override(
     [['ip' => '10.0.0.5', 'label' => 'host', 'ports' => ['61208', '61209']]],
     false, 'http://10.0.0.5:61208/', ['61208', '61209']
   ) === [['ip' => '10.0.0.5', 'label' => 'host', 'ports' => ['61208']]]);

ok('something published => unchanged regardless of what the web address says',
   staxx_address_webui_override(addrs('8080'), true, 'http://192.168.202.64:80/', ['8080'])
     === addrs('8080'));

ok('no web address at all => unchanged',
   staxx_address_webui_override(addrs('8080'), false, '', ['8080']) === addrs('8080'));

ok('a web address with no port => unchanged',
   staxx_address_webui_override(addrs('8080'), false, 'http://192.168.202.64/admin', ['8080'])
     === addrs('8080'));

ok('declared list empty, web port known => the web port shows',
   staxx_address_webui_override(addrs('8080'), false, 'http://192.168.202.64:80/', [])
     === [['ip' => '192.168.202.64', 'label' => '192.168.202.64', 'ports' => ['80']]]);

$multi = [
  ['ip' => '192.168.202.64', 'label' => '192.168.202.64', 'ports' => ['8080']],
  ['ip' => '192.168.202.65', 'label' => '192.168.202.65', 'ports' => ['8080']],
];
ok('more than one address entry: each one gets the port swapped',
   staxx_address_webui_override($multi, false, 'http://[IP]:80/', ['8080']) === [
     ['ip' => '192.168.202.64', 'label' => '192.168.202.64', 'ports' => ['80']],
     ['ip' => '192.168.202.65', 'label' => '192.168.202.65', 'ports' => ['80']],
   ]);

ok('the web port matches one of several declared ports => the list still becomes just that one',
   staxx_address_webui_override(
     [['ip' => '10.0.0.5', 'label' => 'host', 'ports' => ['8080', '9000']]],
     false, 'http://10.0.0.5:9000/', ['8080', '9000']
   ) === [['ip' => '10.0.0.5', 'label' => 'host', 'ports' => ['9000']]]);

/* ---------------------------------------- the route each service would take -- */

echo "\n       staxx_webui_for(), for reading — no outbound requests made:\n";
foreach ($stacks as $s) {
  foreach ($s['services'] as $service) {
    $err = '';
    $url = staxx_webui_for($s['name'], $service, $err);
    if ($url === '') continue;
    printf("       %-30s %s\n", $s['name'].'/'.$service, $url);
  }
}

echo "\n       Address column, for reading — what each running service's row now prints:\n";
foreach ($stacks as $s) {
  if (empty($s['running'])) continue;
  foreach (staxx_stack_containers($s) as $c) {
    $service = $c['service'] !== '' ? $c['service'] : $c['name'];
    $err     = '';
    $url     = staxx_webui_for($s['name'], $service, $err);
    $rec     = staxx_container_net()[$c['id']] ?? [];

    $addresses = staxx_address_webui_override(
      $rec['addresses'] ?? [], $rec['published'] ?? false, $url, $rec['exposed'] ?? []
    );
    $shown = implode(' | ', array_map(
      fn($a) => ($a['label'] ?? $a['ip']).':'.implode(',', $a['ports'] ?? []),
      $addresses
    ));
    printf("       %-30s %s\n", $s['name'].'/'.$service, $shown !== '' ? $shown : '(no address)');
  }
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
