<?PHP
/* StaXX — the network map: who holds which address and port on this server.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR (PLAN_251)
 *
 * One read-only picture of the server's networks for three callers: the
 * Network map window (staxx_netmap), the start-failure dialog that offers a
 * free address when a fixed one is taken (staxx_ip_clash), and the editor's
 * warning about a taken address (staxx_netmap_vlan_ips, via
 * staxx_import_taken_facts). staxx_set_service_ip is the one writer: it
 * swaps a single address in a compose file and nothing else.
 *
 * "Free" for a macvlan or ipvlan address means: Docker has not handed it out,
 * no stack file in the store claims it, and (best effort) nothing answers a
 * single ping. The ping is capped so a request can never hang.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';
require_once '/usr/local/emhttp/plugins/staxx/include/CrossLinks.php';

/** Networks whose containers each hold their own address on the LAN. */
function staxx_netmap_is_vlan(string $driver): bool {
  return $driver === 'macvlan' || $driver === 'ipvlan';
}

/**
 * Every Docker network that can hold addresses, from ONE inspect over all of
 * them. The `null` and `host` drivers carry no addresses and are left out.
 * Container addresses arrive as "192.0.2.10/24"; the mask is dropped.
 *
 * @return array<int, array{name:string, driver:string, subnet:string, gateway:string,
 *                          containers:array<int, array{name:string, ip:string}>}>
 */
function staxx_netmap_networks(): array {
  static $memo = null;
  if ($memo !== null) return $memo;
  $memo = [];
  if (!staxx_docker_running()) return $memo;

  $docker = escapeshellarg(staxx_docker_bin());
  $out    = staxx_sh($docker.' network ls -q | xargs -r '.$docker.' network inspect', 15);
  $list   = json_decode($out, true);
  if (!is_array($list)) return $memo;

  foreach ($list as $n) {
    $driver = (string)($n['Driver'] ?? '');
    if ($driver === 'null' || $driver === 'host') continue;
    $subnet = $gateway = '';
    foreach ((array)($n['IPAM']['Config'] ?? []) as $cfg) {
      $s = (string)($cfg['Subnet'] ?? '');
      if ($s !== '' && strpos($s, ':') === false) {   // first IPv4 subnet only
        $subnet  = $s;
        $gateway = (string)($cfg['Gateway'] ?? '');
        break;
      }
    }
    $containers = [];
    foreach ((array)($n['Containers'] ?? []) as $c) {
      $ip = explode('/', (string)($c['IPv4Address'] ?? ''))[0];
      if ($ip === '') continue;
      $containers[] = ['name' => (string)($c['Name'] ?? ''), 'ip' => $ip];
    }
    $memo[] = ['name' => (string)($n['Name'] ?? ''), 'driver' => $driver,
               'subnet' => $subnet, 'gateway' => $gateway, 'containers' => $containers];
  }
  return $memo;
}

/** @return array<string,string> container name => its compose project ('' for none) */
function staxx_netmap_projects(): array {
  $by = [];
  foreach (staxx_docker_inspect_rows() as $c) $by[ltrim($c[5], '/')] = $c[8];
  return $by;
}

/**
 * Compose project name => the StaXX stack that runs under it, worked out the
 * way the stack list does. A project no stack claims maps to itself.
 *
 * @return array<string,string>
 */
function staxx_netmap_project_stacks(): array {
  $map = [];
  foreach (staxx_stack_compose_map() as $rel => $file) {
    $p = staxx_stack_project_guess($file, staxx_path_leaf($rel));
    if ($p !== '') $map[$p] = $rel;
  }
  return $map;
}

/** IPv4 of the interface holding the default route, '' when there is none. */
function staxx_netmap_host_ip(): string {
  $out = staxx_sh('ip -4 route get 1.1.1.1', 5);
  return preg_match('/\bsrc (\d{1,3}(?:\.\d{1,3}){3})/', $out, $m) ? $m[1] : '';
}

/**
 * Every address a stack file in the store pins with ipv4_address, running or
 * not. A plain text scan: it catches stacks that have never started without
 * paying for a `compose config` per stack.
 *
 * @return string[]
 */
function staxx_netmap_claimed_ips(): array {
  $ips = [];
  foreach (staxx_stack_compose_map() as $file) {
    foreach (staxx_compose_files($file) as $f) {
      $text = (string)@file_get_contents($f);
      if (preg_match_all('/^\s*ipv4_address\s*:\s*["\']?(\d{1,3}(?:\.\d{1,3}){3})/m', $text, $m)) {
        foreach ($m[1] as $ip) $ips[$ip] = true;
      }
    }
  }
  return array_keys($ips);
}

/**
 * The lowest address in $cidr that is not in $used (plus the network and
 * broadcast addresses), pinged once each so a phone or printer holding it
 * is skipped. At most $maxPings pings per call: past that the next unpinged
 * candidate is returned with checked:false. A ping that cannot run at all
 * (no ping binary, timeout) also ends the search with checked:false.
 *
 * @param  string[] $used
 * @return ?array{ip:string, checked:bool} null when the subnet is full or unusable
 */
function staxx_netmap_next_free(string $cidr, array $used, int $maxPings = 6): ?array {
  [$base, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
  $b = ip2long($base);
  $bits = (int)$bits;
  if ($b === false || $bits < 8 || $bits > 30) return null;
  $shift = 32 - $bits;
  $net   = ($b >> $shift) << $shift;
  $size  = 1 << $shift;
  $taken = array_flip($used);
  $pings = 0;
  $last  = min($size - 2, 70000);   // a /16 is scanned only as far as it has to be
  for ($i = 1; $i <= $last; $i++) {
    $ip = long2ip($net + $i);
    if (isset($taken[$ip])) continue;
    if ($pings >= $maxPings) return ['ip' => $ip, 'checked' => false];
    $pings++;
    staxx_sh('ping -c1 -W1 '.escapeshellarg($ip), 3, $code);
    if ($code === 0) continue;                       // something answered: not free
    return ['ip' => $ip, 'checked' => $code === 1];  // 1 = no reply; anything else = could not ask
  }
  return null;
}

/** What Unraid's own programs are called on the ports list; others show their own name. */
function staxx_netmap_service_name(string $holder): string {
  static $names = [
    'nginx' => 'web page', 'emhttpd' => 'web page', 'sshd' => 'SSH', 'inetd' => 'Telnet',
    'smbd' => 'file sharing', 'nmbd' => 'file sharing', 'ntpd' => 'time service',
    'rsyslogd' => 'system log', 'upsd' => 'UPS service',
    'wsdd2' => 'network discovery', 'avahi-daemon' => 'network discovery',
  ];
  return $names[$holder] ?? ($holder !== '' ? $holder : 'system');
}

/**
 * The ports list's rows that are not published bridge ports (pure, so canned
 * input can test it): every listening socket, plus what a stopped
 * host-network app says it will use. Nothing is filtered by port number.
 *
 * $listeners is staxx_parse_ss_listeners() output (docker-proxy already gone);
 * $pidCid maps a listener's pid to the 64-hex id of the container it runs in
 * ('' or missing: the server's own process); $hostById maps a host-network
 * container's id to {name, stack, running, declared:[{port, proto}]}.
 * A process in any other container is skipped: it is not on the server's
 * address. Same port, protocol and app (IPv4 and IPv6) fold into one row.
 *
 * @return array<int, array{port:int, proto:string, app:string, stack:string, net:string, inner:null, expected:bool}>
 */
function staxx_netmap_server_ports(array $listeners, array $pidCid, array $hostById): array {
  $rows = [];
  $add = function (int $port, string $proto, string $app, string $stack, string $net, bool $expected) use (&$rows): void {
    $rows[$port.'/'.$proto.'/'.$app] = ['port' => $port, 'proto' => $proto, 'app' => $app, 'stack' => $stack,
                                        'net' => $net, 'inner' => null, 'expected' => $expected];
  };
  foreach ($listeners as $l) {
    $port = (int)$l['port'];
    $cid  = (string)($pidCid[(int)($l['pid'] ?? 0)] ?? '');
    if ($cid === '') {
      $add($port, $l['proto'], 'Unraid · '.staxx_netmap_service_name((string)$l['holder']), '', 'Unraid', false);
    } elseif (isset($hostById[$cid])) {
      $add($port, $l['proto'], $hostById[$cid]['name'], $hostById[$cid]['stack'], 'host', false);
    }
  }
  foreach ($hostById as $h) {
    if ($h['running']) continue;
    foreach ($h['declared'] as $d) {
      $k = $d['port'].'/'.$d['proto'].'/'.$h['name'];
      if (!isset($rows[$k])) $add($d['port'], $d['proto'], $h['name'], $h['stack'], 'host', true);
    }
  }
  return array_values($rows);
}

/** Joins the two port lists, sorted by port then app (then protocol). */
function staxx_netmap_merge_ports(array $bridge, array $server): array {
  $all = array_merge($bridge, $server);
  usort($all, fn($a, $b) => [$a['port'], $a['app'], $a['proto']] <=> [$b['port'], $b['app'], $b['proto']]);
  return $all;
}

/**
 * The whole map for the Network map window.
 *
 * Networks with no container on them are left out unless they are macvlan or
 * ipvlan (those always get a next free address). `ports` is every port on the
 * server's address: published bridge ports, what host-network apps and
 * Unraid's own programs listen on, and a stopped host app's declared ports
 * (expected:true); `listening` is the bare list of numbers anything listens on.
 */
function staxx_netmap(): array {
  $stackOf = staxx_netmap_project_stacks();
  $project = staxx_netmap_projects();
  $stackFor = function (string $container) use ($project, $stackOf): string {
    $p = $project[$container] ?? '';
    return $p === '' ? '' : ($stackOf[$p] ?? $p);
  };

  $claimed  = null;   // read only when a vlan network needs it
  $networks = [];
  foreach (staxx_netmap_networks() as $n) {
    $vlan = staxx_netmap_is_vlan($n['driver']);
    if (!$n['containers'] && !$vlan) continue;
    $apps = [];
    foreach ($n['containers'] as $c) {
      $apps[] = ['name' => $c['name'], 'ip' => $c['ip'], 'stack' => $stackFor($c['name'])];
    }
    usort($apps, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

    $free = null;
    if ($vlan && $n['subnet'] !== '') {
      if ($claimed === null) $claimed = staxx_netmap_claimed_ips();
      $used = array_merge([$n['gateway']], array_column($apps, 'ip'), $claimed);
      $free = staxx_netmap_next_free($n['subnet'], $used);
    }
    $networks[] = ['name' => $n['name'], 'driver' => $n['driver'], 'subnet' => $n['subnet'],
                   'gateway' => $n['gateway'], 'apps' => $apps, 'nextFree' => $free];
  }
  usort($networks, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

  $ports = [];
  $hostApps = [];
  $hostById = [];   // full container id => host-network app, for the ports it holds
  foreach (staxx_docker_inspect_rows() as $c) {
    $name = ltrim($c[5], '/');
    $mode = $c[1];
    if ($mode === 'host') {
      $hostApps[] = ['name' => $name, 'stack' => $stackFor($name)];
      $declared = [];
      foreach (explode(',', $c[4]) as $p) {
        if (!preg_match('/^(\d+)\/(tcp|udp)$/', $p, $m)) continue;
        $declared[] = ['port' => (int)$m[1], 'proto' => $m[2]];
      }
      $hostById[$c[0]] = ['name' => $name, 'stack' => $stackFor($name), 'running' => false, 'declared' => $declared];
      continue;
    }
    if ($mode === 'none' || strpos($mode, 'container:') === 0) continue;
    $net  = $mode === 'default' ? 'bridge' : $mode;
    $seen = [];   // "0.0.0.0" and "::" bindings repeat the same port
    foreach (explode("\x1f", trim($c[6])) as $p) {
      if ($p === '' || strpos($p, '=') === false) continue;
      [$portProto, $host] = explode('=', $p, 2);
      [$inner, $proto] = array_pad(explode('/', $portProto, 2), 2, 'tcp');
      if (!ctype_digit($host) || !ctype_digit($inner) || isset($seen[$host.'/'.$proto])) continue;
      $seen[$host.'/'.$proto] = true;
      $ports[] = ['port' => (int)$host, 'proto' => $proto, 'app' => $name,
                  'stack' => $stackFor($name), 'net' => $net, 'inner' => (int)$inner, 'expected' => false];
    }
  }
  usort($hostApps, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

  // One look at everything listening: the free-ports card uses the bare
  // numbers, the ports list also needs who holds each.
  $listeners = staxx_parse_ss_listeners(staxx_sh('ss -ltunpH', 5));
  $listening = [];
  foreach ($listeners as $l) $listening[(int)$l['port']] = true;
  $listening = array_keys($listening);
  sort($listening);

  if ($hostById) {
    $docker = escapeshellarg(staxx_docker_bin());
    foreach (explode("\n", staxx_sh($docker.' ps -q --no-trunc --filter network=host', 10)) as $id) {
      if (isset($hostById[trim($id)])) $hostById[trim($id)]['running'] = true;
    }
  }
  $pidCid = [];   // pid => container id ('' when the process is the server's own)
  foreach ($listeners as $l) {
    $pid = (int)$l['pid'];
    if ($pid > 0 && !isset($pidCid[$pid])) {
      $pidCid[$pid] = preg_match('/[0-9a-f]{64}/', (string)@file_get_contents('/proc/'.$pid.'/cgroup'), $m) ? $m[0] : '';
    }
  }
  $ports = staxx_netmap_merge_ports($ports, staxx_netmap_server_ports($listeners, $pidCid, $hostById));

  return ['hostIp' => staxx_netmap_host_ip(), 'networks' => $networks, 'ports' => $ports,
          'hostApps' => $hostApps, 'listening' => $listening];
}

/**
 * Every address a container holds on a macvlan or ipvlan network, for the
 * editor's taken-address warning (staxx_import_taken_facts' `ips`).
 *
 * @return array<int, array{ip:string, network:string, container:string, project:string}>
 */
function staxx_netmap_vlan_ips(): array {
  $project = staxx_netmap_projects();
  $out = [];
  foreach (staxx_netmap_networks() as $n) {
    if (!staxx_netmap_is_vlan($n['driver'])) continue;
    foreach ($n['containers'] as $c) {
      $out[] = ['ip' => $c['ip'], 'network' => $n['name'], 'container' => $c['name'],
                'project' => $project[$c['name']] ?? ''];
    }
  }
  return $out;
}

/**
 * The fixed addresses a stack's services ask for, from the rendered compose
 * config: service => [network real name => ip]. Compose writes every
 * network's real name under networks.<key>.name, so that is used when present.
 *
 * @return array<string, array<string,string>>
 */
function staxx_netmap_fixed_ips(string $file): array {
  $flat = staxx_crosslinks_config_flat($file);
  $out  = [];
  foreach ($flat as $path => $value) {
    $p = explode("\0", $path);
    if ($p[0] !== 'services' || count($p) !== 5 || $p[2] !== 'networks' || $p[4] !== 'ipv4_address') continue;
    $real = $flat["networks\0".$p[3]."\0name"] ?? $p[3];
    $out[$p[1]][$real] = $value;
  }
  return $out;
}

/**
 * For each service of $stack with a fixed ipv4_address: is that address
 * already taken? A container of another project holding it is a clash; so is
 * an address nothing in Docker holds but a ping on a macvlan/ipvlan network
 * answers (holder ''). The stack's own containers never count. Each clash
 * carries a suggested free address on the same network, never the same one
 * twice in one answer.
 *
 * @return array<int, array{service:string, ip:string, network:string, holder:string, free:?array}>
 */
function staxx_ip_clash(string $stack): array {
  if (!staxx_valid_path($stack)) return [];
  $file = staxx_find_compose_file(staxx_stack_dir($stack));
  if ($file === '') return [];
  $mine = staxx_stack_project_guess($file, staxx_path_leaf($stack));

  $byName = [];
  foreach (staxx_netmap_networks() as $n) $byName[$n['name']] = $n;
  $project = staxx_netmap_projects();
  $claimed = null;
  $handed  = [];   // suggestions already given in this answer

  $out = [];
  foreach (staxx_netmap_fixed_ips($file) as $service => $perNet) {
    foreach ($perNet as $netName => $ip) {
      $n = $byName[$netName] ?? null;
      if ($n === null) continue;
      $holder = null;   // null: nobody holds it; '' is never used here
      $ownHolds = false;
      foreach ($n['containers'] as $c) {
        if ($c['ip'] !== $ip) continue;
        if (($project[$c['name']] ?? '') === $mine) $ownHolds = true; else $holder = $c['name'];
      }
      if ($ownHolds && $holder === null) continue;
      $vlan = staxx_netmap_is_vlan($n['driver']);
      if ($holder === null) {
        if (!$vlan) continue;
        staxx_sh('ping -c1 -W1 '.escapeshellarg($ip), 3, $code);
        if ($code !== 0) continue;
        $holder = '';
      }

      $free = null;
      if ($vlan && $n['subnet'] !== '') {
        if ($claimed === null) $claimed = staxx_netmap_claimed_ips();
        $used = array_merge([$n['gateway'], $ip], array_column($n['containers'], 'ip'), $claimed, $handed);
        $free = staxx_netmap_next_free($n['subnet'], $used);
        if ($free) $handed[] = $free['ip'];
      }
      $out[] = ['service' => $service, 'ip' => $ip, 'network' => $netName,
                'holder' => $holder, 'free' => $free];
    }
  }
  return $out;
}

/**
 * Swap one service's `ipv4_address: <from>` for <to> in the text, touching
 * nothing else (quotes, trailing comment and line ending stay). Null with
 * $error set unless the service holds that line exactly once.
 */
function staxx_netmap_replace_ip(string $body, string $service, string $from, string $to, string &$error): ?string {
  $error = '';
  $lines = preg_split('/(?<=\n)/', $body, -1, PREG_SPLIT_NO_EMPTY);
  $inServices = false;
  $svcIndent = null;
  $start = -1;
  foreach ($lines as $i => $l) {
    $t = rtrim($l, "\r\n");
    $trim = ltrim($t);
    if ($trim === '' || $trim[0] === '#') continue;
    $indent = strlen($t) - strlen($trim);
    if (!$inServices) {
      if ($indent === 0 && preg_match('/^services\s*:\s*(#.*)?$/', $t)) $inServices = true;
      continue;
    }
    if ($indent === 0) break;
    if ($svcIndent === null) $svcIndent = $indent;
    if ($indent === $svcIndent && preg_match('/^(["\']?)'.preg_quote($service, '/').'\1\s*:\s*(#.*)?$/', $trim)) {
      $start = $i;
      break;
    }
  }
  if ($start < 0) {
    $error = 'The service "'.$service.'" was not found in this stack\'s compose file, so nothing was changed.';
    return null;
  }

  $hits = [];
  $rx = '/^(\s*ipv4_address\s*:\s*)(["\']?)'.preg_quote($from, '/').'\2(\s*(?:#.*)?)$/';
  for ($j = $start + 1; $j < count($lines); $j++) {
    $t = rtrim($lines[$j], "\r\n");
    $trim = ltrim($t);
    if ($trim === '' || $trim[0] === '#') continue;
    if (strlen($t) - strlen($trim) <= $svcIndent) break;   // next service
    if (preg_match($rx, $t)) $hits[] = $j;
  }
  if (count($hits) !== 1) {
    $error = count($hits) === 0
      ? 'The address '.$from.' was not found on its own line in the "'.$service.'" service, so nothing was changed. Open the editor and change it there.'
      : 'The address '.$from.' appears more than once in the "'.$service.'" service, so nothing was changed. Open the editor and change it there.';
    return null;
  }
  $j = $hits[0];
  $eol = substr($lines[$j], strlen(rtrim($lines[$j], "\r\n")));
  $lines[$j] = preg_replace($rx, '${1}${2}'.$to.'${2}${3}', rtrim($lines[$j], "\r\n")).$eol;
  return implode('', $lines);
}

/**
 * Change one service's fixed address from $from to $to, through the same
 * save every editor save uses, so it lands in the stack's history and can be
 * undone. $to must be an IPv4 address inside the subnet of the network the
 * old address sits on, and not that network's own, broadcast or gateway
 * address. Returns '' on success, else a plain sentence.
 */
function staxx_set_service_ip(string $stack, string $service, string $from, string $to): string {
  if (!staxx_valid_path($stack)) return 'Invalid stack name.';
  $ipRx = '/^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)$/';
  if (!preg_match($ipRx, $from) || !preg_match($ipRx, $to)) return 'That is not a valid IPv4 address.';
  $locked = staxx_stack_locked($stack, 'changing its address');
  if ($locked !== '') return $locked;

  $file = staxx_find_compose_file(staxx_stack_dir($stack));
  if ($file === '') return 'No compose file found in this stack.';

  // Which network the old address is on decides which subnet the new one must fit.
  $netName = '';
  foreach (staxx_netmap_fixed_ips($file)[$service] ?? [] as $n => $ip) if ($ip === $from) $netName = $n;
  $subnet = $gateway = '';
  foreach (staxx_netmap_networks() as $n) {
    if ($n['name'] === $netName) { $subnet = $n['subnet']; $gateway = $n['gateway']; }
  }
  if ($subnet === '') return 'Docker does not know the network that address belongs to, so it cannot be checked. Open the editor and change it there.';
  [$base, $bits] = explode('/', $subnet);
  $shift = 32 - (int)$bits;
  $net   = (ip2long($base) >> $shift) << $shift;
  $t     = ip2long($to);
  if (($t >> $shift) << $shift !== $net) return $to.' is not inside the network '.$subnet.'.';
  if ($t === $net || $t === $net + (1 << $shift) - 1 || $to === $gateway) {
    return $to.' cannot be used: it is the network\'s own, broadcast or gateway address.';
  }

  $body = @file_get_contents($file);
  if ($body === false) return 'Could not read '.$file;
  $new = staxx_netmap_replace_ip($body, $service, $from, $to, $error);
  if ($new === null) return $error;

  // A stack with no history yet keeps its current text as version one first,
  // so the change can be undone (the start job does the same).
  $note = '';
  staxx_record_seed($stack, $note);
  $note = '';
  if (!staxx_save_stack($stack, $new, $error, $note)) return $error;
  return '';
}
