<?PHP
/* StaXX — what actually changed, for the "restart to apply" panel.
 * Copyright 2026, StaXX contributors.
 *
 * staxx_restart_pending() (Stacks.php) only ever says WHICH service stopped
 * matching, because the fingerprint it compares — compose's own
 * config-hash — is deliberately one-way. This file answers the follow-up
 * question, "matching what?", by comparing the compose file against the
 * RUNNING CONTAINER directly. It is deliberately never called from that
 * function, or from anything staxx_list_stacks() drives: staxx_restart_
 * pending() runs for every row on every render, and a docker inspect per
 * service would make every render pay for a comparison nobody is looking
 * at. This runs once, on demand, when the panel is actually opened.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/CrossLinks.php';

// Guarded on its own entry point rather than a constant, since this file
// declares none — same early return every other include here uses.
if (function_exists('staxx_pending_detail')) return;

/**
 * One bind mount as a single comparable string. Both sides are stripped of a
 * trailing slash first: a compose file may write "/mnt/user/appdata/x/" while
 * docker records the same folder as "/mnt/user/appdata/x", and comparing them
 * raw reports one unchanged mount as both added and removed. That is exactly
 * the crying-wolf a person learns to ignore, so the two spellings are made
 * one here rather than at each call site. A lone "/" keeps its slash, since
 * stripping it would leave nothing at all.
 */
function staxx_pending_mount_key(string $host, string $container): string {
  $trim = function (string $p): string {
    $t = rtrim($p, '/');
    return $t === '' ? '/' : $t;
  };
  return $trim($host).':'.$trim($container);
}

/**
 * A generic reader for a service's `ports:` or `volumes:` sequence in
 * `docker compose config` output — the compose spec's normalised long
 * syntax always shapes a sequence item the same way:
 *
 *   ports:
 *     - target: 80
 *       published: "8080"
 *       protocol: tcp
 *
 * staxx_yaml_flatten() SKIPS every sequence on purpose, so this is a second,
 * narrow pass over the same text — the same approach staxx_first_ports()
 * (Stacks.php) already takes for exactly this reason, generalised to read
 * every item of a named sequence instead of stopping at the first, and to
 * collect whichever scalar field names the caller asks for rather than one
 * fixed pair.
 *
 * @param string   $yaml    docker compose config's own output
 * @param string   $seqKey  the top-level service key to read ('ports', 'volumes')
 * @param string[] $fields  scalar field names worth keeping from each item
 * @return array<string, array<int, array<string,string>>> service => list of items
 */
function staxx_pending_read_items(string $yaml, string $seqKey, array $fields): array {
  $out = [];

  $atServices    = false;
  $service       = null;
  $serviceIndent = null;

  $inSeq      = false;
  $seqIndent  = null;
  $itemIndent = null;
  $fieldIndent = null;

  $items = [];
  $cur   = [];

  $pushItem = function () use (&$cur, &$items) {
    if ($cur !== []) $items[] = $cur;
    $cur = [];
  };
  $save = function () use (&$out, &$service, &$items, $pushItem) {
    $pushItem();
    if ($service !== null && $items !== []) $out[$service] = $items;
    $items = [];
  };

  $fieldPattern = '/^('.implode('|', array_map(fn($f) => preg_quote($f, '/'), $fields)).')\s*:\s*(.*)$/';

  foreach (explode("\n", $yaml) as $raw) {
    $line = rtrim($raw, "\r");
    if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

    $indent = strlen($line) - strlen(ltrim($line, ' '));
    $body   = ltrim($line, ' ');

    if (!$atServices) {
      if ($indent === 0 && $body === 'services:') $atServices = true;
      continue;
    }

    if ($indent === 0) { $save(); break; }   // a sibling of services: — done

    if ($serviceIndent === null) $serviceIndent = $indent;

    if ($indent === $serviceIndent) {
      $save();
      $service    = rtrim($body, ':');
      $inSeq      = false; $seqIndent = null; $itemIndent = null; $fieldIndent = null;
      continue;
    }

    if ($service === null) continue;

    if ($indent === $serviceIndent + 2) {
      $save();
      $inSeq      = ($body === $seqKey.':');
      $seqIndent  = $indent; $itemIndent = null; $fieldIndent = null;
      continue;
    }

    if (!$inSeq || $indent <= $seqIndent) continue;

    $isItem = strncmp($body, '- ', 2) === 0 || $body === '-';

    if ($isItem) {
      if ($itemIndent === null) {
        $itemIndent  = $indent;             // the sequence's own indent level
        $fieldIndent = $indent + 2;         // where a continuation line's fields sit
      } elseif ($indent === $itemIndent) {
        $pushItem();                        // a new item — bank the one just finished
      } else {
        continue;                           // deeper still — a list nested inside this item, not our shape
      }
      $body = ltrim(substr($body, 1));       // drop the leading '-'
    } else {
      // A continuation line belongs to the current item only at exactly the
      // one indent its own fields sit at; anything deeper is a nested
      // mapping under one of those fields (e.g. volumes' `bind:` block) and
      // is not a field name this function was asked to read.
      if ($fieldIndent === null || $indent !== $fieldIndent) continue;
    }

    if (preg_match($fieldPattern, $body, $m)) {
      $value = trim($m[2]);
      if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")) {
        $value = substr($value, 1, -1);
      }
      if ($value !== '') $cur[$m[1]] = $value;
    }
  }

  $save();   // services: was the last thing in the file — nothing dedented to catch it

  return $out;
}

/**
 * Every service's intent, read from the same `docker compose config` text
 * staxx_crosslinks_config_yaml() already has cached. Ports are reduced to
 * "published:target/protocol" and bind-mount volumes to "host:container" —
 * the same shape the live side is built into below, so the two can be
 * compared with a plain array_diff().
 *
 * @return array<string, array{image:?string, env:array<string,string>,
 *                              ports:string[], volumes:string[]}>
 */
function staxx_pending_parse_file(string $yaml): array {
  $out = [];

  foreach (staxx_yaml_flatten($yaml) as $path => $value) {
    $parts = explode("\0", $path);
    if (count($parts) < 3 || $parts[0] !== 'services') continue;
    $svc = $parts[1];
    if (!isset($out[$svc])) $out[$svc] = ['image' => null, 'env' => [], 'ports' => [], 'volumes' => []];

    if (count($parts) === 3 && $parts[2] === 'image') {
      $out[$svc]['image'] = $value;
    } elseif (count($parts) === 4 && $parts[2] === 'environment') {
      // compose config always normalises environment: to a mapping, whatever
      // shorthand the author wrote it in, so a plain flatten of the scalar
      // keys underneath is enough — no sequence pass needed here.
      $out[$svc]['env'][$parts[3]] = $value;
    }
  }

  foreach (staxx_pending_read_items($yaml, 'ports', ['target', 'published', 'protocol']) as $svc => $items) {
    if (!isset($out[$svc])) $out[$svc] = ['image' => null, 'env' => [], 'ports' => [], 'volumes' => []];
    foreach ($items as $item) {
      // No published side (a bare `- target: 443` in ingress mode) is not
      // reachable from outside the container, so there is nothing to compare.
      if (!isset($item['published'], $item['target'])) continue;
      $out[$svc]['ports'][] = $item['published'].':'.$item['target'].'/'.($item['protocol'] ?? 'tcp');
    }
  }

  foreach (staxx_pending_read_items($yaml, 'volumes', ['type', 'source', 'target']) as $svc => $items) {
    if (!isset($out[$svc])) $out[$svc] = ['image' => null, 'env' => [], 'ports' => [], 'volumes' => []];
    foreach ($items as $item) {
      if (($item['type'] ?? '') !== 'bind' || !isset($item['source'], $item['target'])) continue;
      $out[$svc]['volumes'][] = staxx_pending_mount_key($item['source'], $item['target']);
    }
  }

  return $out;
}

/**
 * One container's relevant facts, straight from `docker inspect` — image,
 * every environment variable (as a name => value map, so the caller can
 * pick out only the names the file itself mentions), published ports and
 * bind-mount volumes in the same "published:target/protocol" /
 * "host:container" shape staxx_pending_parse_file() builds for the file
 * side.
 *
 * Never fatal: a container that vanished mid-request, or a docker that
 * stops answering, both come back as null — one more service the panel
 * simply has nothing to say about, not an error the person sees.
 */
function staxx_pending_inspect_container(string $id): ?array {
  $code = 1;
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' inspect --format '.escapeshellarg('{{json .}}').' '.escapeshellarg($id),
    8, $code
  );
  if ($code !== 0 || trim($out) === '') return null;

  $data = json_decode(trim($out), true);
  if (!is_array($data)) return null;

  $env = [];
  foreach ((array)($data['Config']['Env'] ?? []) as $entry) {
    $eq = strpos((string)$entry, '=');
    if ($eq === false) continue;
    $env[substr($entry, 0, $eq)] = substr($entry, $eq + 1);
  }

  $ports = [];
  foreach ((array)($data['HostConfig']['PortBindings'] ?? []) as $containerPort => $bindings) {
    // "80/tcp" => target 80, protocol tcp.
    if (!preg_match('/^(\d+)\/(\w+)$/', (string)$containerPort, $m)) continue;
    foreach ((array)$bindings as $binding) {
      $hostPort = (string)($binding['HostPort'] ?? '');
      if ($hostPort === '') continue;   // bound with no host side to publish
      $ports[] = $hostPort.':'.$m[1].'/'.$m[2];
    }
  }
  $ports = array_values(array_unique($ports));   // dual-stack (v4+v6) binds repeat the same host port

  $volumes = [];
  foreach ((array)($data['Mounts'] ?? []) as $mount) {
    if (($mount['Type'] ?? '') !== 'bind') continue;
    $source = (string)($mount['Source'] ?? '');
    $dest   = (string)($mount['Destination'] ?? '');
    if ($source === '' || $dest === '') continue;
    $volumes[] = staxx_pending_mount_key($source, $dest);
  }

  return [
    'image'   => (string)($data['Config']['Image'] ?? ''),
    'env'     => $env,
    'ports'   => $ports,
    'volumes' => $volumes,
  ];
}

/**
 * PLAN_71's follow-up: not just THAT a service stopped matching, but WHAT
 * changed — compared against the live container itself, so this stays
 * correct even for a change made outside StaXX entirely (docker restart
 * --env, a hand-edited container, another tool).
 *
 * Deliberately narrow on environment: a container's .Config.Env is mostly
 * NOT from the compose file — it also carries everything the image's own
 * Dockerfile baked in (PATH, LANG, and often dozens more), and comparing the
 * two lists wholesale would report a pile of "removed" variables the file
 * never set in the first place. So only variable NAMES THE FILE ITSELF
 * MENTIONS are ever considered, and only ADDED and CHANGED are reported —
 * never removed. There is no reliable signal for "removed": nothing records
 * what the file said when the container was started, and guessing would
 * produce exactly the noise this whole rule exists to avoid. Do not add it.
 *
 * Environment values are shown UNMASKED. This was asked about and decided
 * deliberately (PLAN_71 follow-up, this session): anyone who changed a
 * password already has it, and this panel is transient — so do not mask
 * these back in "for safety" without re-raising it.
 *
 * Every failure mode here — compose not installed, a container that
 * vanished mid-request, a file that no longer parses — means "no detail
 * available", never a warning the person sees; the panel works exactly as
 * it does today when this returns an empty services list.
 *
 * @return array{ok:bool, error?:string, services?:array}
 */
function staxx_pending_detail(string $name): array {
  if (!staxx_valid_path($name)) return ['ok' => false, 'error' => 'That stack name is not valid.'];

  $dir  = staxx_stack_dir($name);
  $file = staxx_find_compose_file($dir);
  if ($file === '') return ['ok' => true, 'services' => []];

  $leaf = staxx_path_leaf($name);
  $s = [
    'name'    => $name,
    'file'    => $file,
    'leaf'    => $leaf,
    'project' => staxx_project_name($leaf),
  ];

  $containers = staxx_stack_containers($s);
  if ($containers === []) return ['ok' => true, 'services' => []];

  $yaml = staxx_crosslinks_config_yaml($file);
  if ($yaml === '') return ['ok' => true, 'services' => []];   // compose unavailable, or the file no longer resolves

  $fileServices = staxx_pending_parse_file($yaml);
  if ($fileServices === []) return ['ok' => true, 'services' => []];

  $services = [];
  $seen     = [];   // one container per service, same reasoning as staxx_restart_pending()'s own $seen

  foreach ($containers as $c) {
    $svc = (string)$c['service'];
    if ($svc === '' || isset($seen[$svc]) || !isset($fileServices[$svc])) continue;
    $seen[$svc] = true;

    $live = staxx_pending_inspect_container((string)$c['id']);
    if ($live === null) continue;   // vanished, or docker did not answer in time — no detail, not an error

    $fileSvc = $fileServices[$svc];

    $image = null;
    if ($fileSvc['image'] !== null && $fileSvc['image'] !== '' && $live['image'] !== ''
        && $fileSvc['image'] !== $live['image']) {
      $image = ['from' => $live['image'], 'to' => $fileSvc['image']];
    }

    $env = [];
    foreach ($fileSvc['env'] as $varName => $fileVal) {
      if (!array_key_exists($varName, $live['env'])) {
        $env[] = ['name' => $varName, 'from' => null, 'to' => $fileVal];
      } elseif ($live['env'][$varName] !== $fileVal) {
        $env[] = ['name' => $varName, 'from' => $live['env'][$varName], 'to' => $fileVal];
      }
    }

    $ports = [
      'added'   => array_values(array_diff($fileSvc['ports'], $live['ports'])),
      'removed' => array_values(array_diff($live['ports'], $fileSvc['ports'])),
    ];
    $volumes = [
      'added'   => array_values(array_diff($fileSvc['volumes'], $live['volumes'])),
      'removed' => array_values(array_diff($live['volumes'], $fileSvc['volumes'])),
    ];

    $hasSomething = $image !== null || $env !== []
      || $ports['added'] !== [] || $ports['removed'] !== []
      || $volumes['added'] !== [] || $volumes['removed'] !== [];

    if ($hasSomething) {
      $services[$svc] = [
        'image'   => $image,
        'env'     => $env,
        'ports'   => $ports,
        'volumes' => $volumes,
      ];
    }
  }

  return ['ok' => true, 'services' => $services];
}
