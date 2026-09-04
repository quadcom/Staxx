<?PHP
/* StaXX — live statistics for stacks and their containers.
 * Copyright 2026, StaXX contributors.
 *
 * Nothing in this file runs docker. A background collector samples the machine
 * (see scripts/stats-collector.sh) and drops raw output in a state directory;
 * everything here reads those files and turns them into numbers the page can
 * draw. That split matters: sampling costs about two seconds on a busy server,
 * and no page request should ever wait that long.
 *
 * WHAT CAN AND CANNOT BE ATTRIBUTED TO A CONTAINER
 *
 *   CPU, memory, network, disk    per container, from `docker stats`.
 *   Intel and AMD GPU             per container, from /proc/<pid>/fdinfo. The
 *                                 open source drivers publish per-process GPU
 *                                 time there, and a PID traces back to its
 *                                 container through /proc/<pid>/cgroup.
 *   Nvidia GPU                    needs nvidia-smi; the proprietary driver does
 *                                 not fill in fdinfo.
 *
 * PLAN_114 removed the whole-machine GPU card these per-process figures used
 * to sit beside — a stack's own row is now the only place a GPU figure is
 * shown, so a card-wide reading with no container to attach it to has no
 * home left to be drawn in. The kernel's own gpu_busy_percent and radeontop
 * would have read 0% during a real AMD encode anyway, because they watch the
 * graphics pipe while the work is on the video encode engine — fdinfo saw it
 * the whole time, which is why the per-process figures were always preferred
 * over them for AMD too.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

if (defined('STAXX_STATS_DIR')) return;

define('STAXX_STATS_DIR', '/tmp/staxx/stats');
define('STAXX_STATS_SCRIPT', STAXX_ROOT.'/scripts/stats-collector.sh');

/* ------------------------------------------------------------ collector -- */

/**
 * Say "somebody is watching", and make sure the collector is running.
 *
 * The heartbeat is the whole lifecycle. The collector stops on its own once
 * this timestamp goes stale, so closing the page is all it takes to stop the
 * sampling — there is nothing to shut down and nothing left behind.
 */
function staxx_stats_touch(): void {
  // No data store means no stacks, so there is nothing for the collector to
  // sample — exit quietly rather than starting a background process to
  // watch an empty list.
  if (!staxx_store_ready()) return;

  if (!is_dir(STAXX_STATS_DIR)) @mkdir(STAXX_STATS_DIR, 0755, true);
  @file_put_contents(STAXX_STATS_DIR.'/watch', (string)time());

  if (staxx_stats_collector_running()) return;
  if (!is_file(STAXX_STATS_SCRIPT)) return;

  // Detached, with its input and output disconnected, so it outlives this
  // request instead of being killed when PHP finishes.
  @exec('setsid sh '.escapeshellarg(STAXX_STATS_SCRIPT).' '
        .escapeshellarg(STAXX_STATS_DIR).' </dev/null >/dev/null 2>&1 &');
}

function staxx_stats_collector_running(): bool {
  $pidFile = STAXX_STATS_DIR.'/collector.pid';
  if (!is_file($pidFile)) return false;
  $pid = (int)trim((string)@file_get_contents($pidFile));
  return $pid > 0 && is_dir('/proc/'.$pid);
}

/* -------------------------------------------------------------- parsing -- */

/**
 * Turn docker's human-readable sizes into bytes.
 *
 * Docker mixes two unit systems in the same output — "269.3MiB" for memory
 * (powers of 1024) and "301MB" for network (powers of 1000) — so both are
 * handled rather than assuming one.
 */
function staxx_bytes(string $text): float {
  $text = trim($text);
  if ($text === '' || $text === '--') return 0.0;
  if (!preg_match('/^([0-9.]+)\s*([A-Za-z]*)$/', $text, $m)) return 0.0;

  $value = (float)$m[1];
  $unit  = strtolower($m[2]);

  $scale = [
    ''    => 1,
    'b'   => 1,
    'kb'  => 1000,          'kib' => 1024,
    'mb'  => 1000 ** 2,     'mib' => 1024 ** 2,
    'gb'  => 1000 ** 3,     'gib' => 1024 ** 3,
    'tb'  => 1000 ** 4,     'tib' => 1024 ** 4,
    'pb'  => 1000 ** 5,     'pib' => 1024 ** 5,
  ];

  return $value * ($scale[$unit] ?? 1);
}

/** Split docker's "301MB / 617kB" into two byte counts. */
function staxx_byte_pair(string $text): array {
  $parts = explode('/', $text);
  return [
    staxx_bytes($parts[0] ?? ''),
    staxx_bytes($parts[1] ?? ''),
  ];
}

function staxx_percent(string $text): float {
  return (float)str_replace('%', '', trim($text));
}

/* ----------------------------------------------------------- containers -- */

/**
 * Which stack each running container belongs to.
 *
 * @return array{byId: array<string,array>, byName: array<string,array>}
 */
function staxx_stats_index(): array {
  $byId = [];
  $byName = [];

  foreach (@file(STAXX_STATS_DIR.'/ps.raw', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 3) continue;
    $row = [
      'id'      => $cols[0],
      'name'    => $cols[1],
      'project' => $cols[2] ?? '',
      'service' => $cols[3] ?? '',
    ];
    $byId[$cols[0]]   = $row;
    $byName[$cols[1]] = $row;
  }

  return ['byId' => $byId, 'byName' => $byName];
}

/**
 * Per-container figures from the last `docker stats` sample.
 *
 * @return array<string, array> keyed by container name
 */
function staxx_stats_containers(): array {
  $out = [];

  foreach (@file(STAXX_STATS_DIR.'/docker.raw', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $row = json_decode($line, true);
    if (!is_array($row) || !isset($row['Name'])) continue;

    [$memUsed, $memLimit] = staxx_byte_pair((string)($row['MemUsage'] ?? ''));
    [$netRx,   $netTx]    = staxx_byte_pair((string)($row['NetIO']    ?? ''));
    [$blkRead, $blkWrite] = staxx_byte_pair((string)($row['BlockIO']  ?? ''));

    $out[$row['Name']] = [
      'id'       => (string)($row['Container'] ?? ''),
      'name'     => (string)$row['Name'],
      'cpu'      => staxx_percent((string)($row['CPUPerc'] ?? '')),
      'memUsed'  => $memUsed,
      'memLimit' => $memLimit,
      'memPct'   => staxx_percent((string)($row['MemPerc'] ?? '')),
      // Network and disk counters are totals since the container started, not
      // rates. The page turns them into per-second figures by comparing two
      // samples, which is the only way to get a rate out of a total.
      'netRx'    => $netRx,
      'netTx'    => $netTx,
      'blkRead'  => $blkRead,
      'blkWrite' => $blkWrite,
      'pids'     => (int)($row['PIDs'] ?? 0),
      'gpu'      => 0.0,
    ];
  }

  return $out;
}

/* ------------------------------------------------------------------ GPU -- */

/* -------------------------------------------- per-container GPU, kernel -- */

/**
 * Read one sample file written by sample_gpu_procs().
 *
 * Lines are: e <container> <driver> <pdev> <client> <engine> <nanoseconds>
 *
 * A process opens the same card several times — the encoder here held four
 * file descriptors — and each carries the SAME drm-client-id and the same
 * running total. Adding them up would report four times the real figure, so
 * one value is kept per client and engine. Separate clients are genuinely
 * separate work and are added together.
 *
 * @return array{at: float, engines: array<string, array<string, float>>}
 */
function staxx_gpu_sample(string $file): array {
  $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
  if (!$lines) return ['at' => 0.0, 'engines' => []];

  $at = (float)array_shift($lines);   // nanoseconds

  $perClient = [];   // container => engine => client => ns
  $driver    = [];   // container => driver name

  foreach ($lines as $line) {
    $f = explode(' ', $line);
    if (count($f) < 7 || $f[0] !== 'e') continue;

    [, $container, $drv, $pdev, $client, $engine, $ns] = $f;
    $driver[$container] = $drv;

    // Highest reading wins for a given client and engine: the descriptors are
    // read microseconds apart and the counter only ever climbs.
    $seen = $perClient[$container][$engine][$client] ?? 0.0;
    $perClient[$container][$engine][$client] = max($seen, (float)$ns);
  }

  $engines = [];
  foreach ($perClient as $container => $byEngine) {
    foreach ($byEngine as $engine => $clients) {
      $engines[$container][$engine] = array_sum($clients);
    }
  }

  return ['at' => $at, 'engines' => $engines, 'driver' => $driver];
}

/**
 * How busy each container's GPU is, as a percentage.
 *
 * The busiest single engine is reported rather than the sum of all of them.
 * A container decoding on one engine while encoding on another is using two
 * different parts of the chip at once; adding those together can exceed 100%
 * and would describe something that is not happening.
 *
 * @return array<string, array{busy: float, engines: array<string,float>, driver: string}>
 */
function staxx_stats_gpu_procs(): array {
  $now  = staxx_gpu_sample(STAXX_STATS_DIR.'/gpuproc.raw');
  $then = staxx_gpu_sample(STAXX_STATS_DIR.'/gpuproc.prev');

  if ($now['at'] <= 0 || $then['at'] <= 0) return [];

  $elapsedNs = $now['at'] - $then['at'];
  if ($elapsedNs <= 0) return [];

  $out = [];
  foreach ($now['engines'] as $container => $engines) {
    $busiest = 0.0;
    $detail  = [];

    foreach ($engines as $engine => $ns) {
      $before = $then['engines'][$container][$engine] ?? null;
      if ($before === null) continue;          // first sighting: no rate yet

      $delta = $ns - $before;
      if ($delta < 0) continue;                // process restarted, counter reset

      $percent = ($delta / $elapsedNs) * 100;
      if ($percent > 100) $percent = 100;      // several engine instances in parallel

      if ($percent > 0.05) $detail[$engine] = round($percent, 1);
      if ($percent > $busiest) $busiest = $percent;
    }

    $out[$container] = [
      'busy'    => round($busiest, 1),
      'engines' => $detail,
      'driver'  => $now['driver'][$container] ?? '',
    ];
  }

  return $out;
}

/* --------------------------------------------------- which GPU is which -- */

/**
 * Work out what each /dev/dri node actually is.
 *
 * Node numbering is not fixed — renderD128 is the Intel card on this machine
 * and could be the AMD one on another, depending on probe order. So the vendor
 * is read from the kernel rather than assumed:
 *
 *     /sys/class/drm/renderD128/device/vendor  ->  0x8086
 *
 * @return array<string,string> node name => 'intel' | 'amd' | 'nvidia'
 */
function staxx_gpu_nodes(): array {
  static $nodes = null;
  if ($nodes !== null) return $nodes;

  $vendors = ['0x8086' => 'intel', '0x1002' => 'amd', '0x10de' => 'nvidia'];
  $nodes   = [];

  foreach ((array)@glob('/sys/class/drm/*/device/vendor') as $path) {
    $node = basename(dirname(dirname($path)));
    $id   = strtolower(trim((string)@file_get_contents($path)));
    if (isset($vendors[$id])) $nodes[$node] = $vendors[$id];
  }

  return $nodes;
}

/**
 * Which vendors this plugin can attribute to a single container, and why not
 * when it cannot. Shown in the interface so an empty figure is explained
 * rather than just blank.
 *
 * @return array<string,array{ok:bool, why:string}>
 */
function staxx_gpu_support(): array {
  // Intel and AMD are both read from /proc/<pid>/fdinfo, which the open source
  // drivers fill in and which needs no external tool. Nvidia's proprietary
  // driver does not populate it, so that one still depends on nvidia-smi.
  $fdinfo = is_dir('/sys/class/drm');

  return [
    'intel' => [
      'ok'  => $fdinfo,
      'why' => $fdinfo ? '' : 'No DRM devices found',
    ],
    'amd' => [
      'ok'  => $fdinfo,
      'why' => $fdinfo ? '' : 'No DRM devices found',
    ],
    'nvidia' => [
      'ok'  => staxx_have('nvidia-smi'),
      'why' => staxx_have('nvidia-smi')
        ? '' : 'nvidia-smi is not installed (the Nvidia Driver plugin provides it)',
    ],
  ];
}

function staxx_have(string $binary): bool {
  static $seen = [];
  if (!isset($seen[$binary])) {
    $seen[$binary] = staxx_sh('command -v '.escapeshellarg($binary), 5) !== '';
  }
  return $seen[$binary];
}

/**
 * Which containers have a GPU deliberately handed to them, and whose.
 *
 * Three ways a container gets one:
 *   - a device mapping, /dev/dri or a single node inside it, or /dev/nvidia*
 *   - a device request, which is what `--gpus` and compose's `deploy.resources`
 *     produce for Nvidia
 *   - being privileged, which grants everything
 *
 * The last is recorded but deliberately does not count. Containers are
 * privileged for all sorts of reasons — on this server iVentoy, glances and
 * scrutiny all are, and none of them is a GPU user. Treating privilege as a
 * GPU mapping would put a figure against containers that will never produce
 * one. If such a container really does use the GPU, the measurement itself
 * gives it away and the column fills in anyway.
 *
 * @return array<string, array{vendors: string[], privileged: bool}> by container id
 */
function staxx_stats_gpu_mapped(): array {
  $nodes = staxx_gpu_nodes();
  $out   = [];

  foreach (@file(STAXX_STATS_DIR.'/devices.raw', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 4) continue;

    [$id, $devices, $requests, $privileged] = $cols;

    $vendors = [];

    foreach (explode(',', $devices) as $device) {
      $device = trim($device);
      if ($device === '') continue;

      // The whole directory means every card on the machine.
      if ($device === '/dev/dri' || $device === '/dev/dri/') {
        foreach ($nodes as $vendor) $vendors[$vendor] = true;
        continue;
      }
      // A single node: look up whose it is.
      if (preg_match('#^/dev/dri/(\w+)$#', $device, $m) && isset($nodes[$m[1]])) {
        $vendors[$nodes[$m[1]]] = true;
        continue;
      }
      if (strpos($device, '/dev/nvidia') === 0) {
        $vendors['nvidia'] = true;
      }
    }

    // `--gpus` and compose's deploy.resources.reservations.devices. The
    // collector emits one "request," per entry rather than a count, because
    // counting them in a Go template fails on the null this field holds for
    // every container that never asked for a GPU.
    if (trim($requests) !== '') $vendors['nvidia'] = true;

    if ($vendors || $privileged === 'true') {
      $out[$id] = [
        'vendors'    => array_keys($vendors),
        'privileged' => $privileged === 'true',
      ];
    }
  }

  return $out;
}

/* ------------------------------------------------------------- snapshot -- */

/**
 * Everything the page needs, in one reply.
 *
 * Container figures are rolled up per stack, because a stack is what the table
 * shows. The per-container detail is included as well so a stack can be
 * broken down without another round trip.
 */
function staxx_stats_snapshot(): array {
  staxx_stats_touch();

  $index      = staxx_stats_index();
  $containers = staxx_stats_containers();
  $mapped     = staxx_stats_gpu_mapped();
  $support    = staxx_gpu_support();
  $gpuProcs   = staxx_stats_gpu_procs();

  // Attach GPU usage to the container it belongs to.
  foreach ($containers as $name => &$c) {
    // The kernel's own per-process figures cover both Intel and AMD, and see
    // the video engines a machine-wide reading would miss entirely, so they
    // are the only per-container GPU source.
    if (isset($gpuProcs[$c['id']])) {
      $c['gpu']        = $gpuProcs[$c['id']]['busy'];
      $c['gpuEngines'] = $gpuProcs[$c['id']]['engines'];
    } else {
      $c['gpuEngines'] = [];
    }
    $c['gpu'] = round($c['gpu'], 1);

    // Does this container have a GPU at all, and can its share of one be
    // measured? A container with no GPU shows nothing rather than a zero, so
    // the column stays quiet for the great majority that never use one.
    $c['gpuVendors'] = $mapped[$c['id']]['vendors'] ?? [];
    $c['gpuMapped']  = $c['gpuVendors'] !== [];

    $c['gpuMeasurable'] = false;
    $c['gpuWhy']        = '';
    foreach ($c['gpuVendors'] as $vendor) {
      if ($support[$vendor]['ok'] ?? false) {
        $c['gpuMeasurable'] = true;
      } elseif ($c['gpuWhy'] === '') {
        // Spelled out rather than ucfirst()ed: that produced "Amd:" and
        // "Nvidia:", neither of which is how either company writes its name.
        $proper = ['intel' => 'Intel', 'amd' => 'AMD', 'nvidia' => 'NVIDIA'];
        $c['gpuWhy'] = ($proper[$vendor] ?? $vendor).': '
                     . ($support[$vendor]['why'] ?? 'not measurable');
      }
    }

    // A privileged container that is demonstrably using the GPU is shown even
    // though privilege alone was not treated as a mapping. A real measurement
    // outranks a rule about intent.
    if (!$c['gpuMapped'] && $c['gpu'] > 0) {
      $c['gpuMapped'] = $c['gpuMeasurable'] = true;
    }
  }
  unset($c);

  $stacks = [];
  foreach ($containers as $name => $c) {
    $project = $index['byName'][$name]['project'] ?? '';
    if ($project === '') continue;

    if (!isset($stacks[$project])) {
      $stacks[$project] = [
        'cpu' => 0.0, 'memUsed' => 0.0, 'memLimit' => 0.0,
        'netRx' => 0.0, 'netTx' => 0.0,
        'blkRead' => 0.0, 'blkWrite' => 0.0,
        'pids' => 0, 'gpu' => 0.0, 'containers' => [],
        // A stack counts as having a GPU if any one of its containers does.
        'gpuMapped' => false, 'gpuMeasurable' => false,
        'gpuVendors' => [], 'gpuWhy' => '',
      ];
    }

    $s = &$stacks[$project];
    $s['cpu']      += $c['cpu'];
    $s['memUsed']  += $c['memUsed'];
    // The limit is the same host figure repeated per container, so the largest
    // is the right one to keep. Adding them would report sixty times the RAM
    // this machine has.
    $s['memLimit']  = max($s['memLimit'], $c['memLimit']);
    $s['netRx']    += $c['netRx'];
    $s['netTx']    += $c['netTx'];
    $s['blkRead']  += $c['blkRead'];
    $s['blkWrite'] += $c['blkWrite'];
    $s['pids']     += $c['pids'];
    $s['gpu']      += $c['gpu'];

    if ($c['gpuMapped'])     $s['gpuMapped']     = true;
    if ($c['gpuMeasurable']) $s['gpuMeasurable'] = true;
    if ($c['gpuWhy'] !== '' && $s['gpuWhy'] === '') $s['gpuWhy'] = $c['gpuWhy'];
    $s['gpuVendors'] = array_values(array_unique(
      array_merge($s['gpuVendors'], $c['gpuVendors'])
    ));

    // The same field names as the stack total above, deliberately: the page
    // draws a container row and a stack row with one piece of code, and it can
    // only do that if both describe themselves the same way.
    $s['containers'][] = [
      'name'          => $c['name'],
      'service'       => $index['byName'][$name]['service'] ?? '',
      'cpu'           => round($c['cpu'], 2),
      'memUsed'       => $c['memUsed'],
      'netRx'         => $c['netRx'],
      'netTx'         => $c['netTx'],
      'gpu'           => $c['gpu'],
      'gpuMapped'     => $c['gpuMapped'],
      'gpuMeasurable' => $c['gpuMeasurable'],
      'gpuVendors'    => $c['gpuVendors'],
      'gpuWhy'        => $c['gpuWhy'],
    ];
    unset($s);
  }

  foreach ($stacks as &$s) {
    $s['cpu'] = round($s['cpu'], 2);
    $s['gpu'] = round($s['gpu'], 1);
  }
  unset($s);

  $sampledAt = (int)trim((string)@file_get_contents(STAXX_STATS_DIR.'/docker.at'));

  return [
    'stacks'    => $stacks,
    // The page shows how old the figures are rather than implying they are
    // live. A collector that has died is then obvious instead of looking like
    // a server that has gone quiet.
    'sampledAt' => $sampledAt,
    'age'       => $sampledAt > 0 ? max(0, time() - $sampledAt) : null,
    'now'       => time(),
    'warming'   => $sampledAt === 0,
    'collector' => staxx_stats_collector_running(),
  ];
}
?>
