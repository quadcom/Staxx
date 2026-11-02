<?php
/* staxx_netmap_server_ports() and staxx_netmap_merge_ports() — the network
 * map's ports list beyond published bridge ports (PLAN_253): a host-network
 * app's listening ports, Unraid's own programs, a stopped host app's declared
 * ports marked expected, IPv4/IPv6 folding, and no filtering by port range.
 * Pure functions fed canned `ss` rows, so no real socket or container is read.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/netmap_ports.php root@<box>:/tmp/
 *     plink … "php /tmp/netmap_ports.php"
 *
 * Prints one line per case and exits non-zero on any failure. Touches
 * neither disk nor Docker. */

require_once '/usr/local/emhttp/plugins/staxx/include/NetMap.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$tdarr  = str_repeat('a', 64);
$cooler = str_repeat('b', 64);
$hostById = [
  $tdarr  => ['name' => 'tdarr', 'stack' => 'Media/tdarr', 'running' => true,
              'declared' => [['port' => 8264, 'proto' => 'tcp'], ['port' => 8265, 'proto' => 'tcp']]],
  $cooler => ['name' => 'CoolerControl', 'stack' => 'System/cooler', 'running' => false,
              'declared' => [['port' => 11987, 'proto' => 'tcp'], ['port' => 11987, 'proto' => 'tcp']]],
];
$listeners = [
  ['port' => '8265',  'proto' => 'tcp', 'addr' => '0.0.0.0', 'holder' => 'node',  'pid' => 10],
  ['port' => '8265',  'proto' => 'tcp', 'addr' => '::',      'holder' => 'node',  'pid' => 10],
  ['port' => '41000', 'proto' => 'udp', 'addr' => '0.0.0.0', 'holder' => 'urbackupsrv', 'pid' => 10],
  ['port' => '443',   'proto' => 'tcp', 'addr' => '0.0.0.0', 'holder' => 'nginx', 'pid' => 20],
  ['port' => '80',    'proto' => 'tcp', 'addr' => '0.0.0.0', 'holder' => 'nginx', 'pid' => 20],
  ['port' => '80',    'proto' => 'tcp', 'addr' => '::',      'holder' => 'emhttpd', 'pid' => 21],
  ['port' => '7090',  'proto' => 'tcp', 'addr' => '0.0.0.0', 'holder' => 'unbalanced', 'pid' => 22],
  ['port' => '9999',  'proto' => 'tcp', 'addr' => '*',       'holder' => '', 'pid' => 0],
  ['port' => '3000',  'proto' => 'tcp', 'addr' => '0.0.0.0', 'holder' => 'grafana', 'pid' => 30],
];
$pidCid = [10 => $tdarr, 20 => '', 21 => '', 22 => '', 30 => str_repeat('c', 64)];

$rows = staxx_netmap_server_ports($listeners, $pidCid, $hostById);
$by = [];
foreach ($rows as $r) $by[$r['port'].'/'.$r['proto'].'/'.$r['app']] = $r;

ok('host app listener: net host, stack, inner null, not expected',
   ($by['8265/tcp/tdarr']['net'] ?? '') === 'host' && $by['8265/tcp/tdarr']['stack'] === 'Media/tdarr'
   && $by['8265/tcp/tdarr']['inner'] === null && $by['8265/tcp/tdarr']['expected'] === false);
ok('IPv4 and IPv6 listeners fold into one row', count(array_filter($rows, fn($r) => $r['port'] === 8265)) === 1);
ok('a high UDP port is kept (no range filtering)', isset($by['41000/udp/tdarr']));
ok('Unraid web page: nginx and emhttpd fold into one row',
   isset($by['80/tcp/Unraid · web page']) && count(array_filter($rows, fn($r) => $r['port'] === 80)) === 1);
ok('Unraid row: net Unraid, no stack', ($by['443/tcp/Unraid · web page']['net'] ?? '') === 'Unraid'
   && $by['443/tcp/Unraid · web page']['stack'] === '');
ok('unknown program shows its own name', isset($by['7090/tcp/Unraid · unbalanced']));
ok('no process name shows "system"', isset($by['9999/tcp/Unraid · system']));
ok('a process in a non-host container is skipped', !isset($by['3000/tcp/Unraid · grafana']) && !array_filter($rows, fn($r) => $r['port'] === 3000));
ok('a running host app gets no expected rows', !isset($by['8264/tcp/tdarr']));
ok('stopped host app: declared port, expected, deduplicated',
   ($by['11987/tcp/CoolerControl']['expected'] ?? false) === true
   && count(array_filter($rows, fn($r) => $r['app'] === 'CoolerControl')) === 1);

$bridge = [['port' => 80, 'proto' => 'tcp', 'app' => 'web', 'stack' => '', 'net' => 'bridge', 'inner' => 80, 'expected' => false]];
$all = staxx_netmap_merge_ports($bridge, $rows);
$ports = array_column($all, 'port');
$sorted = $ports; sort($sorted);
ok('merged list is sorted by port', $ports === $sorted);
ok('same port is ordered by app name',
   array_values(array_column(array_filter($all, fn($r) => $r['port'] === 80), 'app')) === ['Unraid · web page', 'web']);

ok('staxx_netmap_service_name maps ssh and falls back', staxx_netmap_service_name('sshd') === 'SSH'
   && staxx_netmap_service_name('foo') === 'foo' && staxx_netmap_service_name('') === 'system');

exit($fails ? 1 : 0);
