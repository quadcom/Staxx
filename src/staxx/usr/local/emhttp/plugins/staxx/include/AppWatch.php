<?PHP
/* StaXX — watching running apps (PLAN_221): restart loops, failed health
 * checks and apps that stopped by themselves.
 * Copyright 2026, StaXX contributors.
 *
 * Run once a minute by scripts/app-watch. Each pass compares one `docker
 * inspect` of every container with the previous pass and hands what changed
 * to staxx_notify_events(). A snapshot compare is used rather than Docker's
 * event log because health checks alone write ~165 events a minute, so a
 * minute's window can fall partly outside what Docker still holds.
 *
 * The snapshot and the "already reported" memory live in /tmp/staxx/appwatch.json
 * on purpose: a reboot wipes them, and the first pass after a reboot only
 * takes a baseline, so containers starting at boot never look like changes.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Notify.php';

/** The state file; STAXX_APPWATCH_FILE lets a suite point it at /tmp. */
function staxx_appwatch_file(): string {
  $f = getenv('STAXX_APPWATCH_FILE');
  return ($f !== false && $f !== '') ? $f : '/tmp/staxx/appwatch.json';
}

/**
 * Every container as it is now, keyed by id, from one `docker ps -aq` and one
 * `docker inspect` over all of them. null when Docker could not be read, so
 * the pass does nothing and keeps the old state rather than seeing every
 * container vanish.
 *
 * @return array<string,array>|null
 */
function staxx_appwatch_snapshot(): ?array {
  $bin = getenv('STAXX_DOCKER_BIN');
  $bin = ($bin !== false && $bin !== '') ? $bin : staxx_docker_bin();
  $code = 1;
  $ids = [];
  $list = staxx_sh(escapeshellarg($bin).' ps -aq --no-trunc', 8, $code);
  if ($code !== 0) return null;
  foreach (preg_split('/\s+/', trim($list)) as $id) if (preg_match('/^[0-9a-f]+$/', $id)) $ids[] = $id;
  if (!$ids) return [];

  // A real tab between the fields: `docker inspect --format` must never be
  // given the two characters \t (see staxx_container_net()'s comment).
  $fields = [
    '{{.Id}}', '{{.Name}}',
    '{{index .Config.Labels "com.docker.compose.project.working_dir"}}',
    '{{index .Config.Labels "com.docker.compose.service"}}',
    '{{.State.Status}}', '{{.State.ExitCode}}', '{{.State.OOMKilled}}', '{{.RestartCount}}',
    // `index`, not .State.Health: inspect's template treats a missing key as
    // an error, and a container with no health check has no Health key, so
    // the dotted form failed the whole call (found on the box, 2026-10-03).
    '{{with index .State "Health"}}{{.Status}}{{end}}',
    '{{with index .State "Health"}}{{.FailingStreak}}{{else}}0{{end}}',
  ];
  $cmd = escapeshellarg($bin).' inspect --format '.escapeshellarg(implode("\t", $fields))
       .' '.implode(' ', array_map('escapeshellarg', $ids));
  $out = staxx_sh($cmd, 8, $code);
  if ($code !== 0) return null;

  $snap = [];
  foreach (explode("\n", $out) as $line) {
    $line = rtrim($line, "\r");
    if ($line === '') continue;
    $p = explode("\t", $line);
    if (count($p) < 10) continue;
    // `index` on a label the container lacks prints "<no value>", not nothing.
    foreach ([2, 3] as $i) if ($p[$i] === '<no value>') $p[$i] = '';
    $snap[$p[0]] = [
      'name'     => ltrim($p[1], '/'),
      'dir'      => $p[2],
      'service'  => $p[3],
      'status'   => $p[4],
      'exitCode' => (int)$p[5],
      'oom'      => $p[6] === 'true',
      'restarts' => (int)$p[7],
      'health'   => $p[8],
      'streak'   => (int)$p[9],
    ];
  }
  return $snap;
}

/**
 * The stack whose compose file sits in $workingDir, as its path under the
 * root; '' when none does (a container outside StaXX).
 *
 * @param array<string,string> $map staxx_stack_compose_map()
 */
function staxx_appwatch_stack_for(string $workingDir, array $map): string {
  $workingDir = rtrim($workingDir, '/');
  if ($workingDir === '') return '';
  foreach ($map as $rel => $file) {
    if ($file !== '' && rtrim(dirname($file), '/') === $workingDir) return (string)$rel;
  }
  return '';
}

/**
 * What changed between two snapshots, as the events staxx_notify_events()
 * takes, and the memory to carry to the next pass. Pure: no I/O, so a suite
 * can drive it. An event's 'stack' comes from its $now entry when set, else
 * the pass fills it from 'dir'.
 *
 * $state per container id: 'restarts' (times Docker restarted it, pruned past
 * an hour) and 'reported' (flags 'restarting', 'unhealthy') so one problem
 * is one message, not one a minute.
 *
 * @return array{0: array[], 1: array}
 */
function staxx_appwatch_events(array $old, array $now, array $state, int $time): array {
  $events = [];
  $new = [];
  $baseline = !$old;   // first pass after boot: remember, say nothing

  foreach ($now as $id => $n) {
    $o  = $old[$id] ?? null;
    $st = $state[$id] ?? [];
    $times = array_values((array)($st['restarts'] ?? []));
    $rep   = (array)($st['reported'] ?? []);
    $stack = (string)($n['stack'] ?? '');
    $base  = ['stack' => $stack, 'dir' => (string)($n['dir'] ?? ''), 'service' => (string)($n['service'] ?? ''), 'name' => (string)($n['name'] ?? ''), 'image' => ''];

    if ($baseline) {
      $new[$id] = ['restarts' => [], 'reported' => ['restarting' => false, 'unhealthy' => ($n['health'] ?? '') === 'unhealthy']];
      continue;
    }

    // Restart loop: Docker's own restarts only (RestartCount ignores a restart someone asked for).
    $rise = $o ? max(0, (int)$n['restarts'] - (int)$o['restarts']) : 0;
    for ($i = 0; $i < $rise; $i++) $times[] = $time;
    $times = array_values(array_filter($times, function ($t) use ($time) { return $time - (int)$t <= 3600; }));
    if (count($times) >= 3) {
      if (empty($rep['restarting'])) {
        $events[] = $base + ['kind' => 'restarting', 'count' => count($times), 'reason' => ''];
        $rep['restarting'] = true;
      }
    } else {
      $rep['restarting'] = false;
    }

    // Health check. Only for a running container: Docker reports a stopped
    // container with a health check as unhealthy, so the nightly Appdata Backup
    // stopping apps looked like failures.
    $health    = (string)($n['health'] ?? '');
    $oldHealth = $o ? (string)$o['health'] : '';
    $restarted = $o && ($rise > 0 || ($o['status'] !== 'running' && $n['status'] === 'running'));
    $wasRep    = !empty($rep['unhealthy']);
    if ($n['status'] !== 'running') {
      // flag kept as it was, no event
    } elseif ($health === 'unhealthy') {
      if ($restarted) $rep['unhealthy'] = false;
      elseif ($oldHealth !== 'unhealthy' && !$wasRep) {
        $events[] = $base + ['kind' => 'unhealthy', 'count' => (int)$n['streak'], 'reason' => ''];
        $rep['unhealthy'] = true;
      }
    } else {
      if ($wasRep && $health === 'healthy') $events[] = $base + ['kind' => 'healthy', 'count' => 0, 'reason' => ''];
      $rep['unhealthy'] = false;
    }

    // Stopped by itself. Clean exits and signal stops (0, 137, 143) are what
    // a person, a backup and a shutdown all produce, so they stay silent. A stop
    // someone asked for (any tool using Docker's stop) is dropped later in the
    // pass by staxx_appwatch_manual_stop().
    if ($o && $o['status'] === 'running' && in_array($n['status'], ['exited', 'dead'], true)) {
      $reason = '';
      if (!empty($n['oom'])) $reason = 'It ran out of memory.';
      elseif (!in_array((int)$n['exitCode'], [0, 137, 143], true)) $reason = 'It stopped with error code '.(int)$n['exitCode'].'.';
      if ($reason !== '') $events[] = $base + ['kind' => 'stopped', 'count' => 0, 'reason' => $reason, 'id' => $id];
    }

    $new[$id] = ['restarts' => $times, 'reported' => ['restarting' => !empty($rep['restarting']), 'unhealthy' => !empty($rep['unhealthy'])]];
  }
  return [$events, $new];
}

/** How long Docker must stay unanswering before the message goes (PLAN_223): longer than an ordinary restart. */
const STAXX_APPWATCH_DOCKER_DOWN_SECS = 300;
/** A server up for less than this is still booting, so Docker not answering is not reported. */
const STAXX_APPWATCH_BOOT_GRACE_SECS = 600;

/** A path a suite can redirect through an environment variable, else the real one. */
function staxx_appwatch_path(string $env, string $default): string {
  $f = getenv($env);
  return ($f !== false && $f !== '') ? $f : $default;
}

/**
 * Docker's data folder: where it keeps one folder per container.
 */
function staxx_appwatch_docker_root(): string {
  $env = getenv('STAXX_APPWATCH_DOCKER_ROOT');
  if ($env !== false && $env !== '') return $env;
  static $root = null;
  if ($root === null) {
    $bin = getenv('STAXX_DOCKER_BIN');
    $bin = ($bin !== false && $bin !== '') ? $bin : staxx_docker_bin();
    $code = 1;
    $out = trim(staxx_sh(escapeshellarg($bin).' info -f '.escapeshellarg('{{.DockerRootDir}}'), 8, $code));
    $root = ($code === 0 && $out !== '' && $out[0] === '/') ? $out : '/var/lib/docker';
  }
  return $root;
}

/**
 * Whether Docker's stop command was used on this container. Docker sets the
 * flag whenever anything stops it that way (a backup tool, a script, Unraid's
 * Docker page) and clears it on start; a crash or out-of-memory kill leaves it
 * false. It is Docker's own file rather than a public interface, so a missing
 * or unreadable file means "tell".
 */
function staxx_appwatch_manual_stop(string $id): bool {
  if (!preg_match('/^[0-9a-f]+$/', $id)) return false;
  $j = json_decode((string)@file_get_contents(staxx_appwatch_docker_root().'/containers/'.$id.'/config.v2.json'), true);
  return is_array($j) && ($j['HasBeenManuallyStopped'] ?? null) === true;
}

/**
 * Whether Docker is switched on in Unraid (DOCKER_ENABLED in docker.cfg). On
 * when the file or the line is missing: a guess towards telling, not silence.
 */
function staxx_appwatch_docker_enabled(): bool {
  $txt = @file_get_contents(staxx_appwatch_path('STAXX_APPWATCH_DOCKERCFG', '/boot/config/docker.cfg'));
  if ($txt === false) return true;
  if (!preg_match_all('/^\s*DOCKER_ENABLED\s*=\s*"?([^"\r\n]*?)"?\s*$/m', $txt, $m)) return true;
  return strtolower(trim((string)end($m[1]))) !== 'no';
}

/**
 * Whether the array is started (mdState in var.ini); Docker cannot run
 * otherwise. Started when unreadable, so a missing file errs towards telling.
 */
function staxx_appwatch_array_started(): bool {
  $txt = @file_get_contents(staxx_appwatch_path('STAXX_APPWATCH_VARINI', '/var/local/emhttp/var.ini'));
  if ($txt === false) return true;
  if (!preg_match_all('/^\s*mdState\s*=\s*"?([^"\r\n]*?)"?\s*$/m', $txt, $m)) return true;
  return strtoupper(trim((string)end($m[1]))) === 'STARTED';
}

/** Seconds the server has been up; very large when unreadable, so a missing file errs towards telling. */
function staxx_appwatch_uptime(): int {
  $txt = @file_get_contents(staxx_appwatch_path('STAXX_APPWATCH_UPTIME', '/proc/uptime'));
  if ($txt === false || !preg_match('/^\s*(\d+)/', $txt, $m)) return PHP_INT_MAX;
  return (int)$m[1];
}

/**
 * Docker itself being down (PLAN_223). Pure: all the rules live here so a suite
 * can drive each case with no Docker and no clock.
 *
 * $rec is the memory from last minute: [] or ['downSince' => unix time,
 * 'reported' => bool]. Down means not answering while Docker is switched on,
 * the array is started and the server is past its boot grace; otherwise any
 * record is dropped and nothing is said. The message goes once Docker has been
 * down STAXX_APPWATCH_DOCKER_DOWN_SECS in a row, once per outage, and one more
 * goes when it answers again after that. 'count' is whole minutes.
 *
 * @return array{0: array[], 1: array} [events, new record]
 */
function staxx_appwatch_docker_step(array $rec, bool $answering, bool $enabled, bool $arrayStarted, int $uptime, int $now): array {
  $since = isset($rec['downSince']) ? (int)$rec['downSince'] : null;
  $blank = ['stack' => '', 'service' => '', 'image' => '', 'name' => ''];

  if ($answering) {
    if ($since !== null && !empty($rec['reported'])) {
      return [[$blank + ['kind' => 'dockerback', 'count' => intdiv(max(0, $now - $since), 60)]], []];
    }
    return [[], []];
  }
  if (!$enabled || !$arrayStarted || $uptime < STAXX_APPWATCH_BOOT_GRACE_SECS) return [[], []];
  if ($since === null) return [[], ['downSince' => $now, 'reported' => false]];
  if (empty($rec['reported']) && $now - $since >= STAXX_APPWATCH_DOCKER_DOWN_SECS) {
    return [[$blank + ['kind' => 'dockerdown', 'count' => intdiv($now - $since, 60)]], ['downSince' => $since, 'reported' => true]];
  }
  return [[], ['downSince' => $since, 'reported' => !empty($rec['reported'])]];
}

/**
 * One look: read last minute's file, snapshot, work out the events, write the
 * file back, hand the events to the message system. A pass still running from
 * last minute holds the lock and this one just leaves.
 */
function staxx_appwatch_pass(): void {
  $file = staxx_appwatch_file();
  $dir = dirname($file);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $lock = @fopen($file.'.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;

  $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
  $old   = is_array($saved['snapshot'] ?? null) ? $saved['snapshot'] : [];
  $state = is_array($saved['state'] ?? null) ? $saved['state'] : [];

  $rec  = is_array($saved['docker'] ?? null) ? $saved['docker'] : [];
  $time = staxx_notify_now();
  // The 'docker' key is only written while a record exists, so a healthy
  // server's file looks as it did before PLAN_223.
  $write = function (array $snapshot, array $st, array $dockerRec) use ($file) {
    $data = ['snapshot' => $snapshot, 'state' => $st];
    if ($dockerRec) $data['docker'] = $dockerRec;
    $tmp = $file.'.tmp';
    if (file_put_contents($tmp, json_encode($data)) !== false) @rename($tmp, $file);
  };

  $now = staxx_appwatch_snapshot();
  if ($now === null) {
    // Docker could not be read: keep the old snapshot and state exactly, and
    // only touch the file when the down record changed.
    [$dockerEvents, $newRec] = staxx_appwatch_docker_step($rec, false, staxx_appwatch_docker_enabled(), staxx_appwatch_array_started(), staxx_appwatch_uptime(), $time);
    if ($newRec !== $rec) $write($old, $state, $newRec);
    if ($dockerEvents) staxx_notify_events($dockerEvents);
    flock($lock, LOCK_UN); fclose($lock);
    return;
  }

  [$dockerEvents, $newRec] = staxx_appwatch_docker_step($rec, true, true, true, PHP_INT_MAX, $time);
  [$events, $newState] = staxx_appwatch_events($old, $now, $state, $time);
  // A stop someone asked for is not the app stopping by itself.
  $events = array_values(array_filter($events, fn($e) => !($e['kind'] === 'stopped' && staxx_appwatch_manual_stop((string)$e['id']))));
  foreach ($events as $i => $e) unset($events[$i]['id']);

  // The store is only walked when there is something to say, not every minute.
  if ($events) {
    $map = staxx_stack_compose_map();
    foreach ($events as $i => $e) {
      if ($e['stack'] === '') $events[$i]['stack'] = staxx_appwatch_stack_for($e['dir'], $map);
      unset($events[$i]['dir']);
    }
  }

  $write($now, $newState, $newRec);
  // Docker events carry no dir or stack lookup, so they join after the loop above.
  $events = array_merge($dockerEvents, $events);
  if ($events) staxx_notify_events($events);

  flock($lock, LOCK_UN);
  fclose($lock);
}
