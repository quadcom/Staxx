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
 * radeontop and the Intel sysfs counters are still sampled, but only as a
 * machine-wide backstop — and they are the WEAKER reading, not the stronger
 * one. During a real AMD encode both radeontop and the kernel's own
 * gpu_busy_percent report 0%, because they watch the graphics pipe while the
 * work is on the video encode engine. fdinfo saw it the whole time. On a
 * media server that is the case that matters, so the per-process figures come
 * first and these are only a fallback.
 *
 * The Intel figure no longer comes from intel_gpu_top. That tool attaches to
 * the i915 perf/PMU interface, and the collector was starting it and then
 * killing it under a timeout every few seconds, indefinitely, for as long as
 * any StaXX page stayed open. On a server whose discrete Arc card also does
 * the Plex hardware transcode and which had unexplained GPU crashes, that is
 * a needless risk for a number plain sysfs reads already give: the busy
 * percentage is worked out from how much the card's idle-time counter grew
 * between two readings, with nothing ever attached to the GPU.
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

/**
 * Parse one intel.raw/intel.prev sysfs sample.
 *
 * First line is a millisecond timestamp; the rest are "i <card> <rc6_ms>
 * <freq_mhz> <max_freq_mhz>", one per Intel card found — see sample_intel()
 * in the collector.
 *
 * @return array{at: float, cards: array<string, array{rc6: float, freq: float, max: float}>}
 */
function staxx_gpu_intel_sample(string $file): array {
  $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
  if (!$lines) return ['at' => 0.0, 'cards' => []];

  $at = (float)array_shift($lines);

  $cards = [];
  foreach ($lines as $line) {
    $f = explode(' ', $line);
    if (count($f) < 5 || $f[0] !== 'i') continue;
    $cards[$f[1]] = ['rc6' => (float)$f[2], 'freq' => (float)$f[3], 'max' => (float)$f[4]];
  }

  return ['at' => $at, 'cards' => $cards];
}

/**
 * A card's busy percentage from two idle-residency readings.
 *
 * rc6_residency_ms counts upward, in milliseconds, the total time the card
 * has spent in its idle power state — so the share of the elapsed interval it
 * was NOT idle is the busy figure. Verified on the real hardware: over a
 * 2004ms interval the idle counter grew by 2002ms, i.e. 0% busy, agreeing
 * with a clock speed of 0 MHz at the same moment.
 *
 * Clamped to 0–100 rather than trusted outright, because the counter can go
 * backwards — a card reset, or the 32-bit value wrapping — and an interval of
 * zero (two readings taken in the same millisecond) must read as "no data"
 * rather than divide by zero.
 *
 * @param float $idleBefore rc6_residency_ms at the earlier reading
 * @param float $idleAfter  rc6_residency_ms at the later reading
 * @param float $elapsedMs  wall time between the two readings
 */
function staxx_gpu_busy_percent(float $idleBefore, float $idleAfter, float $elapsedMs): float {
  if ($elapsedMs <= 0) return 0.0;

  $idleDelta = $idleAfter - $idleBefore;
  if ($idleDelta < 0) return 0.0;               // counter reset or wrapped

  $idlePercent = ($idleDelta / $elapsedMs) * 100;
  $busy = 100 - $idlePercent;

  if ($busy < 0)   $busy = 0.0;                 // idle grew faster than time itself
  if ($busy > 100) $busy = 100.0;

  return $busy;
}

/**
 * Intel GPU figures, read straight from sysfs — no external tool, nothing
 * attached to the card. See sample_intel() in the collector for why this
 * replaced intel_gpu_top, and staxx_gpu_busy_percent() for the maths.
 *
 * `engines` is always empty here: only intel_gpu_top ever broke usage down by
 * render/video/enhance engine, and nothing in sysfs offers that. The strip
 * already draws an empty engines list for the AMD card, so this path is
 * already exercised elsewhere on the page.
 *
 * `byContainer` is deliberately left empty too. Per-container attribution for
 * Intel now comes only from the kernel's own per-process accounting in
 * staxx_stats_gpu_procs() (fdinfo), which staxx_stats_snapshot() already
 * prefers over this figure — this used to be a second, weaker route to the
 * same number and duplicating it would double-count nothing usefully.
 *
 * @return array{byContainer: array<string,float>, engines: array<string,float>, total: float, present: bool}
 */
function staxx_stats_intel_gpu(): array {
  $empty = ['byContainer' => [], 'engines' => [], 'total' => 0.0, 'present' => false];

  $now  = staxx_gpu_intel_sample(STAXX_STATS_DIR.'/intel.raw');
  $then = staxx_gpu_intel_sample(STAXX_STATS_DIR.'/intel.prev');
  if (!$now['cards']) return $empty;

  $elapsedMs = $now['at'] - $then['at'];

  $busiest = 0.0;
  foreach ($now['cards'] as $card => $reading) {
    $before = $then['cards'][$card] ?? null;
    if ($before === null) continue;             // first sighting: no rate yet

    $busy = staxx_gpu_busy_percent($before['rc6'], $reading['rc6'], $elapsedMs);
    $busiest = max($busiest, $busy);
  }

  return [
    'byContainer' => [],
    'engines'     => [],
    'total'       => round($busiest, 1),
    // A card that answered is reported even when it is idle. Dropping it
    // while idle would make a working GPU look like a missing one, which is
    // the opposite of what the reading is for.
    'present'     => true,
  ];
}

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

/**
 * Which vendor owns each DRM minor device number.
 *
 * The kernel's client list identifies a card by its minor number — 129 rather
 * than renderD129 — so the node names staxx_gpu_nodes() knows about have to
 * be turned into numbers. /sys/class/drm/<node>/dev holds "226:129".
 *
 * @return array<int,string> minor => 'intel' | 'amd' | 'nvidia'
 */
function staxx_gpu_minors(): array {
  static $minors = null;
  if ($minors !== null) return $minors;

  $minors = [];
  foreach (staxx_gpu_nodes() as $node => $vendor) {
    $dev = trim((string)@file_get_contents('/sys/class/drm/'.$node.'/dev'));
    if (preg_match('/^\d+:(\d+)$/', $dev, $m)) $minors[(int)$m[1]] = $vendor;
  }
  return $minors;
}

/**
 * How many pieces of work each card is running, machine-wide.
 *
 * COUNTED THE SAME WAY FOR EVERY CARD, which is the point of it. The busy
 * figures come from different tools that disagree about how to describe an
 * unused card, and a header line reading "Intel idle, AMD 0%" is one state told
 * two ways — it looks like the two cards differ when they do not.
 *
 * A card that exists always appears, with 0 if nothing is on it: absent and
 * idle are different, and the line has to be able to say which.
 *
 * @return array<string,int> vendor => number of clients
 */
function staxx_stats_gpu_clients(): array {
  $out    = ['intel' => 0, 'amd' => 0, 'nvidia' => 0];
  $minors = staxx_gpu_minors();

  foreach (@file(STAXX_STATS_DIR.'/gpuclients.raw', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $f = explode(' ', trim($line));
    if (count($f) < 3 || $f[0] !== 'c') continue;

    $vendor = $minors[(int)$f[1]] ?? '';
    if ($vendor !== '') $out[$vendor]++;
  }

  return $out;
}

/* ------------------------------------------------- AMD video engine -- */

/**
 * Where the multimedia-engine activity field sits in each version of AMD's
 * gpu_metrics table, keyed "format.content".
 *
 * The table is a versioned C structure published byte for byte through sysfs,
 * and the layout is fixed for a given version — the offsets below are counted
 * from the struct definitions the driver exposes. Two families:
 *
 *   v1.x  discrete cards. Six temperatures, then gfx, umc and mm activity.
 *   v2.x  APUs. Two temperatures, eight per-core, two L3, then gfx and mm.
 *
 * The v2.1 entry is MEASURED, on a Phoenix2 APU: 100% during a flat-out VAAPI
 * encode while radeontop and gpu_busy_percent both read 0%, and the 120-byte
 * size the table declared matched the struct exactly. The rest are read from
 * the same struct definitions but have not been seen on real hardware here, so
 * every reading is bounds-checked below before it is believed.
 *
 * Newer APUs publish a v3.x table with a different shape. It is deliberately
 * absent: an offset nobody has checked is a wrong number waiting to happen,
 * and a card that is not listed simply falls back to radeontop.
 */
const STAXX_GPU_METRICS_MM = [
  '1.0' => 20, '1.1' => 20, '1.2' => 20, '1.3' => 20,
  '2.0' => 30, '2.1' => 30, '2.2' => 30, '2.3' => 30, '2.4' => 30,
];

/**
 * How busy each card's video engine has been, as a share of the last second.
 *
 * The AVERAGE of the samples, not the highest of them. Each sample is an
 * instant, and a transcode keeping pace with real time is genuinely idle
 * between frames, so the readings alternate between nothing and everything.
 * Taking the highest would report 100% for any transcode at all, whether it
 * was one stream or six — true, and useless for judging what the card has
 * left. Averaging instants sampled across a second measures how much of that
 * second the engine was working, which is what "busy" means everywhere else
 * on this page: the Intel figure is the same statistic, reached a different
 * way — the share of the interval its idle-time counter did NOT grow by, see
 * staxx_gpu_busy_percent().
 *
 * The peak is kept as well, since a card that touches 100% is worth knowing
 * about even when its average is modest.
 *
 * @return array<int,array{busy:float, peak:float}> drm minor => figures
 */
function staxx_stats_gpu_media(): array {
  $samples = [];

  foreach (@file(STAXX_STATS_DIR.'/gpumetrics.raw', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $f = explode(' ', trim($line));
    if (count($f) < 3 || $f[0] !== 'g') continue;

    $minor = (int)$f[1];
    $blob  = @hex2bin($f[2]);
    if ($blob === false || strlen($blob) < 8) continue;

    $header = unpack('vsize/Cformat/Ccontent', $blob);

    // The table says how long it is. If that disagrees with what arrived, this
    // is not the structure it claims to be and none of the offsets apply.
    if ($header['size'] !== strlen($blob)) continue;

    $offset = STAXX_GPU_METRICS_MM[$header['format'].'.'.$header['content']] ?? null;
    if ($offset === null || $offset + 2 > strlen($blob)) continue;

    $raw = unpack('v', substr($blob, $offset, 2))[1];

    // 0xFFFF is the driver's "this chip does not report it" marker. Checked
    // FIRST: it would otherwise sail through the scaling below as 655%.
    if ($raw === 0xFFFF) continue;

    // The scale is not consistent between chips. The Phoenix2 measured here
    // reports hundredths of a percent — 10000 at full load — while the field
    // is documented as a plain percentage. Reading it either way costs nothing
    // and cannot go far wrong: the only ambiguous readings are between 1 and
    // 100, where the two interpretations differ by less than one percent of
    // the card. Anything that survives this and is still out of range is not
    // the field we think it is, and is dropped.
    $percent = $raw > 100 ? $raw / 100 : (float)$raw;
    if ($percent < 0 || $percent > 100) continue;

    $samples[$minor][] = $percent;
  }

  $out = [];
  foreach ($samples as $minor => $values) {
    $out[$minor] = [
      'busy' => round(array_sum($values) / count($values), 1),
      'peak' => round(max($values), 1),
    ];
  }
  return $out;
}

/**
 * AMD figures. Machine-wide — see the note at the top of this file.
 *
 * Two sources, because neither alone describes the card:
 *
 *   radeontop     the graphics pipe, plus how much video memory is in use.
 *                 Reads 0% throughout a video transcode.
 *   gpu_metrics   the video engine, which is where a transcode actually runs,
 *                 and nothing else.
 *
 * The headline figure is whichever is higher, and the video engine is listed
 * separately so the two are never confused. Either source may be missing: a
 * machine with no radeontop still gets the transcode figure, and a card whose
 * table version is not recognised still gets the graphics one.
 */
function staxx_stats_amd(): ?array {
  $media = null;
  $peak  = 0.0;
  foreach (staxx_stats_gpu_media() as $minor => $figures) {
    if ((staxx_gpu_minors()[$minor] ?? '') !== 'amd') continue;
    $media = max($media ?? 0.0, $figures['busy']);
    $peak  = max($peak, $figures['peak']);
  }

  $fallback = $media === null ? null : [
    'busy'    => $media,
    'scope'   => 'machine',
    'engines' => $peak > 0 ? ['Video' => $media] : [],
    'peak'    => $peak,
  ];

  $raw = trim((string)@file_get_contents(STAXX_STATS_DIR.'/amd.raw'));
  if ($raw === '') return $fallback;

  $lines = array_filter(explode("\n", $raw), fn($l) => strpos($l, 'gpu ') !== false);
  if (!$lines) return $fallback;
  $line = (string)end($lines);

  $get = function (string $key) use ($line): ?float {
    return preg_match('/\b'.preg_quote($key, '/').'\s+([0-9.]+)%/', $line, $m)
      ? (float)$m[1] : null;
  };

  $busy = $get('gpu');
  if ($busy === null) return $fallback;

  if ($media !== null) {
    // The graphics figure alone would report a transcoding card as idle, which
    // is the whole reason the second source exists.
    $busy = max($busy, $media);
  }

  $out = [
    'busy'    => round($busy, 1),
    'scope'   => 'machine',
    // Listed whenever the engine did anything at all, even if the average
    // rounds to nothing: "Video 0.4%" says a transcode is running, where a
    // bare 0% next to a thread count says nothing useful.
    'engines' => $peak > 0 ? ['Video' => $media] : [],
    'peak'    => $peak,
  ];
  if (preg_match('/\bvram\s+[0-9.]+%\s+([0-9.]+)mb/i', $line, $m)) {
    $out['vram'] = (float)$m[1] * 1024 * 1024;
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
  $intel      = staxx_stats_intel_gpu();
  $amd        = staxx_stats_amd();
  $mapped     = staxx_stats_gpu_mapped();
  $support    = staxx_gpu_support();
  $gpuProcs   = staxx_stats_gpu_procs();
  $clients    = staxx_stats_gpu_clients();

  // Attach GPU usage to the container it belongs to.
  foreach ($containers as $name => &$c) {
    // The kernel's own per-process figures cover both Intel and AMD, and see
    // the video engines that the machine-wide readings miss entirely, so they
    // are the only per-container GPU source now — see staxx_stats_intel_gpu()
    // for why the sysfs reading no longer offers a second one.
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
    // Both cards answer in the same shape — busy, clients, engines, scope — so
    // the header line can render them with one piece of code and cannot end up
    // describing the same state two different ways.
    'gpu'       => [
      'intel' => $intel['present']
        ? ['busy'    => $intel['total'],
           'clients' => $clients['intel'],
           'engines' => $intel['engines'],
           'scope'   => 'container']
        : null,
      'amd'   => $amd === null ? null : $amd + [
        'clients' => $clients['amd'],
        'engines' => [],
      ],
    ],
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
