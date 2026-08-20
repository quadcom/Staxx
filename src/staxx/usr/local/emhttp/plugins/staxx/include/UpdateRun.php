<?PHP
/* StaXX — image update detection: the doing side. Settings, the clock,
 * holding and skipping, the queue, roll back and clean-up.
 * Copyright 2026, StaXX contributors.
 *
 * include/Updates.php is the finding-out side: it asks the registry and
 * remembers the answer. Everything here acts on what it found — deciding
 * when a clock is allowed to run, and pressing the buttons that already
 * exist (staxx_start_job()'s job verbs) rather than inventing new ones.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
// staxx_update_due() and the queue walk the grid in folder order, which lives
// in Folders.php rather than anywhere Stacks.php or Updates.php already pull
// in. action.php happens to require it first anyway, but scripts/update-check
// includes only this file directly for the cron passes, so it has to be
// named here too or staxx_folder_layout() is simply undefined there.
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';

if (defined('STAXX_UPDATERUN_LOADED')) return;
define('STAXX_UPDATERUN_LOADED', true);

/* The quiet window is the one setting whose value only means anything in the
 * server's own timezone: someone typing 03:00 means three in the morning
 * where the box is. A web request already has that, because Unraid's
 * local_prepend.php sets it from /etc/localtime before any plugin code runs
 * — but the cron passes are plain CLI, where PHP falls back to UTC, and on a
 * box four hours behind that the window would open and close four hours out.
 * Read the same source Unraid reads, so both paths agree. '/usr/share/zoneinfo/'
 * is twenty characters, which is what the offset below skips. */
$staxx_update_tz = @readlink('/etc/localtime');
if (is_string($staxx_update_tz) && strpos($staxx_update_tz, '/usr/share/zoneinfo/') === 0) {
  @date_default_timezone_set(substr($staxx_update_tz, 20));
}
unset($staxx_update_tz);

/* --------------------------------------------------------------- settings --
 *
 * The global defaults, typed and range-checked. staxx_settings_keys() (Part F,
 * a different phase) is the browser-facing allowlist; this is what every
 * server-side decision in this file actually reads, so an unrecognised or
 * out-of-range value falls back to the same default the settings panel
 * shows rather than being trusted outright — a hand-edited cfg is not a
 * validated one.
 */

/**
 * @return array{mode:string, delay:int, window:bool, wstart:string, wend:string,
 *               notify:string, retain:int, cleanup:string}
 */
function staxx_update_settings(): array {
  $cfg = staxx_cfg();
  $time = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

  $mode = (string)($cfg['UPDATE_MODE'] ?? 'notify');
  if (!in_array($mode, ['off', 'notify', 'auto'], true)) $mode = 'notify';

  $delay = $cfg['UPDATE_DELAY_HOURS'] ?? 24;
  $delay = (is_numeric($delay) && (int)$delay == $delay) ? (int)$delay : 24;
  if ($delay < 0 || $delay > 720) $delay = 24;

  $window = (string)($cfg['UPDATE_WINDOW'] ?? 'true') === 'true';

  $wstart = (string)($cfg['UPDATE_WINDOW_START'] ?? '03:00');
  if (!preg_match($time, $wstart)) $wstart = '03:00';

  $wend = (string)($cfg['UPDATE_WINDOW_END'] ?? '05:00');
  if (!preg_match($time, $wend)) $wend = '05:00';

  $notify = (string)($cfg['UPDATE_NOTIFY'] ?? 'off');
  if (!in_array($notify, ['off', 'found', 'applied'], true)) $notify = 'off';

  $retain = $cfg['UPDATE_RETAIN'] ?? 2;
  $retain = (is_numeric($retain) && (int)$retain == $retain) ? (int)$retain : 2;
  if ($retain < 0 || $retain > 5) $retain = 2;

  $cleanup = (string)($cfg['UPDATE_CLEANUP'] ?? 'off');
  if ($cleanup !== 'weekly') $cleanup = 'off';

  return ['mode' => $mode, 'delay' => $delay, 'window' => $window, 'wstart' => $wstart,
          'wend' => $wend, 'notify' => $notify, 'retain' => $retain, 'cleanup' => $cleanup];
}

/**
 * The mode and delay that actually apply to one service, resolved service
 * first, then the stack itself, then the global default. A scope only wins
 * outright when it declares at least one of the two keys — a stack that sets
 * only 'update.delay' still inherits the global mode, it does not fall
 * through to the global delay too. An unrecognised mode or a non-numeric,
 * out-of-range delay is ignored at that scope exactly as it would be at the
 * global one, so a typo in a compose file cannot silently turn automatic
 * updates on (or off) for a service.
 *
 * @return array{mode:string, delay:int, from:string}
 */
function staxx_update_policy(string $stack, string $service): array {
  $global = staxx_update_settings();
  $fallback = ['mode' => $global['mode'], 'delay' => $global['delay'], 'from' => 'global'];

  if (!staxx_valid_path($stack)) return $fallback;

  $file = '';
  foreach (staxx_list_stacks() as $s) {
    if ($s['name'] === $stack) { $file = $s['file']; break; }
  }
  if ($file === '') return $fallback;

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return $fallback;

  $modes = ['off', 'notify', 'auto'];
  $scopes = [
    'service' => (array)($meta['services'][$service]['x'] ?? []),
    'stack'   => (array)$meta['x'],
  ];

  foreach ($scopes as $from => $x) {
    $rawMode  = (string)($x['update.mode'] ?? '');
    $rawDelay = $x['update.delay'] ?? null;

    $mode  = in_array($rawMode, $modes, true) ? $rawMode : null;
    $delay = (is_numeric($rawDelay) && (int)$rawDelay == $rawDelay
              && (int)$rawDelay >= 0 && (int)$rawDelay <= 720) ? (int)$rawDelay : null;

    if ($mode !== null || $delay !== null) {
      return [
        'mode'  => $mode ?? $global['mode'],
        'delay' => $delay ?? $global['delay'],
        'from'  => $from,
      ];
    }
  }

  return $fallback;
}

/* ------------------------------------------------------------- the window -- */

/** "HH:MM" to minutes since midnight, for comparing against another clock time. */
function staxx_update_window_minutes(string $hhmm): int {
  $parts = array_map('intval', explode(':', $hhmm));
  return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
}

/**
 * Is $now inside the configured quiet window? Always true when the window is
 * switched off. Handles a window that crosses midnight (23:00–05:00) by
 * treating it as "everything except the gap between end and start", rather
 * than assuming start is always the smaller number.
 */
function staxx_update_window_ok(int $now): bool {
  $s = staxx_update_settings();
  if (!$s['window']) return true;

  $start = staxx_update_window_minutes($s['wstart']);
  $end   = staxx_update_window_minutes($s['wend']);
  if ($start === $end) return true; // a zero-width window restricts nothing

  $cur = (int)date('H', $now) * 60 + (int)date('i', $now);
  if ($start < $end) return $cur >= $start && $cur < $end;
  return $cur >= $start || $cur < $end; // wraps midnight
}

/**
 * The timestamp the window next opens — $now itself when it is already open,
 * so a caller can always show a time rather than branching separately on
 * staxx_update_window_ok(). 0 when the window is off.
 */
function staxx_update_window_next(int $now): int {
  $s = staxx_update_settings();
  if (!$s['window']) return 0;

  $start = staxx_update_window_minutes($s['wstart']);
  $end   = staxx_update_window_minutes($s['wend']);
  if ($start === $end) return 0;

  if (staxx_update_window_ok($now)) return $now;

  $midnight   = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
  $todayStart = $midnight + $start * 60;

  return $todayStart > $now ? $todayStart : $todayStart + 86400;
}

/* ------------------------------------------------------- being edited -- */

/**
 * Touch the marker that says "an editor for this stack has unsaved changes
 * right now" — the browser calls this while the editor is open. Fresh means
 * within 15 minutes, so a tab that was simply closed cannot freeze a stack's
 * updates for ever.
 */
function staxx_update_editing_mark(string $stack): bool {
  if (!staxx_valid_path($stack)) return false;

  $dir = STAXX_UPDATE_DIR.'/editing';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

  return @touch($dir.'/'.md5($stack)) !== false;
}

function staxx_update_editing(string $stack): bool {
  $path = STAXX_UPDATE_DIR.'/editing/'.md5($stack);
  if (!is_file($path)) return false;
  return (time() - (int)@filemtime($path)) < 900;
}

/* --------------------------------------------------------------- the clock -- */

/**
 * What one service's clock is doing right now. 'due' is the timestamp the
 * update would install itself at, computed fresh from 'seen' every call so a
 * page refresh can never restart it; 0 only when there genuinely is no clock
 * running (mode is off/notify, or there is nothing new to install).
 *
 * 'why' is populated whenever an automatic install will NOT actually happen
 * even though the clock is real — paused, held, unreviewed, being edited,
 * stopped, or waiting on the quiet window — so the countdown itself keeps
 * ticking honestly while the row still explains why pressing nothing will
 * not be enough.
 *
 * @return array{due:int, hold:bool, why:string}
 */
function staxx_update_clock(string $stack, string $service, string $image): array {
  $none = ['due' => 0, 'hold' => false, 'why' => ''];

  $policy = staxx_update_policy($stack, $service);
  if ($policy['mode'] !== 'auto') return $none;

  $state = staxx_update_state();
  $entry = (array)($state['images'][$image] ?? []);

  $remote = (string)($entry['remote'] ?? '');
  $seen   = (int)($entry['seen'] ?? 0);
  $skip   = (string)($entry['skip'] ?? '');
  if ($remote === '' || $seen === 0 || ($skip !== '' && $skip === $remote)) return $none;

  $hold = !empty($entry['hold']);
  $due  = $seen + $policy['delay'] * 3600;
  $now  = time();

  $why = '';
  if (!empty($state['paused'])) {
    $why = 'Automatic updates are paused for every stack. Turn the pause switch off to let them run again.';
  } elseif ($hold) {
    $why = 'This update was cancelled here. Press it again to let the clock run.';
  } elseif (staxx_review_locked($stack)) {
    $why = 'This stack was imported and has not been reviewed yet, so it will not update itself.';
  } elseif (staxx_update_editing($stack)) {
    $why = 'This stack is being edited right now, so it will not update itself until you are done.';
  } else {
    $running = false;
    foreach (staxx_list_stacks() as $s) {
      if ($s['name'] === $stack) { $running = $s['running']; break; }
    }
    if (!$running) {
      $why = 'This stack is stopped, so it will not update itself.';
    } elseif ($due <= $now && !staxx_update_window_ok($now)) {
      $why = 'Waiting for the quiet window, opens at '.date('H:i', staxx_update_window_next($now)).'.';
    }
  }

  return ['due' => $due, 'hold' => $hold, 'why' => $why];
}

/**
 * Every service whose clock has run out AND is actually permitted to install
 * itself right now — every refusal staxx_update_clock() can report is a
 * refusal here too. Reads every stack's compose metadata, so it is not for a
 * fast path; the apply pass is the only caller that matters.
 *
 * @return array<int, array{stack:string, service:string, image:string, due:int}>
 */
function staxx_update_due(): array {
  $out = [];
  $now = time();

  foreach (staxx_folder_layout(staxx_list_stacks()) as $row) {
    if ($row['type'] !== 'stack') continue;
    $stack = $row['stack'];
    if ($stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    foreach ($meta['services'] as $svc => $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;

      $clock = staxx_update_clock($stack['name'], $svc, $image);
      if ($clock['due'] > 0 && $clock['due'] <= $now && $clock['why'] === '') {
        $out[] = ['stack' => $stack['name'], 'service' => $svc, 'image' => $image, 'due' => $clock['due']];
      }
    }
  }

  return $out;
}

/* ------------------------------------------------------------ pause / hold -- */

/** The one global switch, state rather than a setting — no settings save needed. */
function staxx_update_pause(bool $on): bool {
  return staxx_update_state_save(['paused' => $on]);
}

/**
 * Cancel (or un-cancel) the clock for one image. Refuses an image with no
 * entry at all, so a typo in a request cannot invent a fresh key in a file
 * nothing else writes to freely.
 */
function staxx_update_hold(string $image, bool $on, string &$error): bool {
  $error = '';
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  if (!array_key_exists($image, $images)) {
    $error = 'This image has not been checked yet, so there is nothing to hold.';
    return false;
  }

  $entry = $images[$image];
  if ($on) $entry['hold'] = true; else unset($entry['hold']);
  $images[$image] = $entry;

  return staxx_update_state_save(['images' => $images]);
}

/* ------------------------------------------------------------------- history -- */

/**
 * Remember one service's fingerprint before an update runs — capped at the
 * retention setting, and never the same digest twice running, which is what
 * keeps a repeatedly-recreated container from filling the list with copies of
 * itself.
 */
function staxx_update_history_push(string $stack, string $service, string $digest): void {
  if ($digest === '') return;

  $key     = $stack.'::'.$service;
  $state   = staxx_update_state();
  $history = (array)$state['history'];
  $list    = (array)($history[$key] ?? []);

  if (($list[0] ?? '') === $digest) return;

  array_unshift($list, $digest);
  $retain = staxx_update_settings()['retain'];
  $history[$key] = array_slice($list, 0, max(0, $retain));

  staxx_update_state_save(['history' => $history]);
}

function staxx_update_history(string $stack, string $service): array {
  $state = staxx_update_state();
  return (array)($state['history'][$stack.'::'.$service] ?? []);
}

/* ------------------------------------------------------------------- roll back -- */

/**
 * Point one service's image back at the version recorded just before its
 * last update, and bring it up. Never edits the compose file — the tag
 * itself is re-pointed with `docker tag`, and the existing 'recreate' verb
 * (already scoped to a single service the same way every other job is) does
 * the rest.
 *
 * @return string a job id, or '' with $error set on refusal
 */
function staxx_update_rollback(string $stack, string $service, string &$error): string {
  $error = '';

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }

  $file = '';
  foreach (staxx_list_stacks() as $s) {
    if ($s['name'] === $stack) { $file = $s['file']; break; }
  }
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }

  $meta = staxx_compose_meta($file);
  if (!$meta['ok'] || !isset($meta['services'][$service])) {
    $error = 'No service called "'.$service.'" in this stack.';
    return '';
  }

  $image = trim((string)($meta['services'][$service]['image'] ?? ''));
  if ($image === '') {
    $error = 'This service has no image set, so there is nothing to roll back.';
    return '';
  }

  $previous = staxx_update_history($stack, $service)[0] ?? '';
  if ($previous === '') {
    $error = 'There is no earlier version recorded for this service, so it cannot be rolled back.';
    return '';
  }

  $repo = staxx_hub_repo_path($image);
  if ($repo === '') $repo = preg_replace('/:[^\/]*$/', '', trim($image));

  $checkCode = 1;
  staxx_sh(
    staxx_docker_bin().' image inspect '.escapeshellarg($repo.'@'.$previous)
      .' --format '.escapeshellarg('{{.Id}}').' 2>&1',
    10, $checkCode
  );
  if ($checkCode !== 0) {
    $error = 'The previous version is no longer present on this server, so it cannot be rolled back to.';
    return '';
  }

  $tagCode = 1;
  staxx_sh(
    staxx_docker_bin().' tag '.escapeshellarg($repo.'@'.$previous).' '.escapeshellarg(trim($image)).' 2>&1',
    15, $tagCode
  );
  if ($tagCode !== 0) {
    $error = 'Could not point the image tag back at the previous version.';
    return '';
  }

  // Remember the version this rolled back FROM as this image's skip
  // fingerprint, so the clock does not immediately try to reinstall the very
  // update just backed out of.
  $state  = staxx_update_state();
  $images = (array)$state['images'];
  if (isset($images[$image]) && ($images[$image]['remote'] ?? '') !== '') {
    $images[$image]['skip'] = $images[$image]['remote'];
    staxx_update_state_save(['images' => $images]);
  }

  return staxx_start_job($stack, 'recreate', $error, $service);
}

/* -------------------------------------------------------------------- cleanup -- */

/**
 * Remove an old image version, but only ever one that is BOTH unused by any
 * container right now AND absent from every service's history list — never a
 * general prune, which would be a foot-gun on a server carrying hand-built
 * images the way this one's own risk note describes.
 *
 * Walks only the repositories staxx_update_images() already tracks, so a
 * repository this plugin knows nothing about is never touched.
 *
 * @return array{removed: string[], kept: int}
 */
/**
 * Normalise a docker image id for comparison: strip an optional
 * 'sha256:' prefix and keep only the leading twelve characters, since
 * `docker image ls` prints the short form and `docker inspect` may print
 * the long one.
 */
function staxx_update_short_id(string $id): string {
  $id = trim($id);
  if (strncmp($id, 'sha256:', 7) === 0) $id = substr($id, 7);
  return substr($id, 0, 12);
}

function staxx_update_cleanup(bool $dry, string &$error): array {
  $error   = '';
  $removed = [];
  $kept    = 0;

  // Fails closed outright when Docker cannot even be asked what is running —
  // an empty "in use" list here would look identical to "nothing is using
  // any of these images" and delete things it never actually checked.
  if (!$dry && !staxx_docker_running()) {
    $error = 'The Docker service is not running, so nothing was removed.';
    return ['removed' => [], 'kept' => 0];
  }

  $state  = staxx_update_state();
  $images = (array)$state['images'];

  // Every digest worth keeping, per repository: the live pointer plus
  // whatever history still remembers for any service using that repository.
  $keep = [];
  foreach ($images as $ref => $entry) {
    $repo = staxx_hub_repo_path($ref);
    if ($repo === '') $repo = preg_replace('/:[^\/]*$/', '', trim($ref));
    if (!empty($entry['local'])) $keep[$repo][] = $entry['local'];
  }
  foreach ((array)$state['history'] as $key => $digests) {
    [$stack, $service] = array_pad(explode('::', $key, 2), 2, '');
    $file = '';
    foreach (staxx_list_stacks() as $s) {
      if ($s['name'] === $stack) { $file = $s['file']; break; }
    }
    if ($file === '') continue;
    $meta = staxx_compose_meta($file);
    $ref  = trim((string)($meta['services'][$service]['image'] ?? ''));
    if ($ref === '') continue;
    $repo = staxx_hub_repo_path($ref);
    if ($repo === '') $repo = preg_replace('/:[^\/]*$/', '', trim($ref));
    foreach ((array)$digests as $d) $keep[$repo][] = $d;
  }

  // Every image a container is actually using, by id, running or stopped —
  // never removed regardless of what the bookkeeping above says.
  // `docker ps --format '{{.Image}}'` prints the REFERENCE a container was
  // started with (usually repo:tag), never a digest and rarely an id, so
  // comparing that against a repo@digest or an id never matched — the guard
  // was doing nothing. `docker inspect` on each container's own id reports
  // its actual Image field, which IS the image id, and that is what
  // `docker image ls`'s own id column can honestly be compared against —
  // after normalising both, since one may print the long sha256:... form and
  // the other the short twelve-character one.
  $docker = escapeshellarg(staxx_docker_bin());
  $used   = [];
  $psOut  = staxx_sh(
    $docker.' ps -aq | xargs -r '.$docker.' inspect --format '.escapeshellarg('{{.Image}}').' 2>&1',
    15
  );
  foreach (explode("\n", $psOut) as $line) {
    $line = trim($line);
    if ($line !== '') $used[staxx_update_short_id($line)] = true;
  }

  foreach (array_keys($keep) as $repo) {
    $listOut = staxx_sh(
      staxx_docker_bin().' image ls --digests --format '
        .escapeshellarg('{{.Repository}}'."\t".'{{.Digest}}'."\t".'{{.ID}}')
        .' '.escapeshellarg($repo),
      10
    );

    foreach (explode("\n", $listOut) as $line) {
      $cols = explode("\t", $line);
      if (count($cols) < 3 || $cols[1] === '<none>' || $cols[1] === '') continue;
      $digest = $cols[1];
      $id     = $cols[2];

      if (in_array($digest, $keep[$repo] ?? [], true)) { $kept++; continue; }
      if (isset($used[staxx_update_short_id($id)])) { $kept++; continue; }

      $ref = $repo.'@'.$digest;
      if ($dry) { $removed[] = $ref; continue; }

      $rmCode = 1;
      staxx_sh(staxx_docker_bin().' rmi '.escapeshellarg($ref).' 2>&1', 20, $rmCode);
      if ($rmCode === 0) $removed[] = $ref; else $kept++;
    }
  }

  return ['removed' => $removed, 'kept' => $kept];
}

/* ---------------------------------------------------------------------- queue -- */

function staxx_update_queue_path(): string {
  return STAXX_UPDATE_DIR.'/queue.json';
}

/** The queue file, decoded — an empty array when there is no queue at all. */
function staxx_update_queue_read(): array {
  $raw  = @file_get_contents(staxx_update_queue_path());
  $data = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? $data : [];
}

/**
 * Written temp-then-rename, the same as staxx_update_state_save() — but this
 * lives in /tmp, not on flash, so unlike that one there is no reason to skip
 * an unchanged write.
 */
function staxx_update_queue_write(array $queue): bool {
  if (!is_dir(STAXX_UPDATE_DIR) && !@mkdir(STAXX_UPDATE_DIR, 0755, true)) return false;

  $encoded = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) return false;

  $tmp = STAXX_UPDATE_DIR.'/.queue.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $encoded) === false) return false;
  if (!@rename($tmp, staxx_update_queue_path())) { @unlink($tmp); return false; }
  return true;
}

/**
 * The queue's own lock, same atomic-mkdir trick as staxx_update_lock() and
 * under its own name so a running check pass and a running queue tick can
 * never block each other.
 */
function staxx_update_queue_lock(string &$error): bool {
  $error = '';
  if (!is_dir(STAXX_UPDATE_DIR) && !@mkdir(STAXX_UPDATE_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_UPDATE_DIR;
    return false;
  }

  $lock = STAXX_UPDATE_DIR.'/queue.lock';
  if (@mkdir($lock, 0755)) return true;

  $age = is_dir($lock) ? (time() - (int)@filemtime($lock)) : 0;
  if ($age > 1800) {
    @rmdir($lock);
    if (@mkdir($lock, 0755)) return true;
  }

  $error = 'The queue is already being updated.';
  return false;
}

function staxx_update_queue_unlock(): void {
  @rmdir(STAXX_UPDATE_DIR.'/queue.lock');
}

/**
 * The current queue, in the shape the page always expects — an empty queue
 * reads the same as one that finished and was never looked at again.
 */
function staxx_update_queue_state(): array {
  $queue = staxx_update_queue_read();
  if (!$queue) {
    return ['id' => '', 'scope' => '', 'stopped' => false, 'includeStopped' => false, 'items' => []];
  }
  return $queue;
}

/**
 * Build a fresh queue over one scope, in grid order, and write it — waiting,
 * not yet started; staxx_update_queue_tick() is what actually runs it.
 *
 * @param string $scope 'all', a folder name, or one stack's path
 */
function staxx_update_queue_start(string $scope, bool $includeStopped, string &$error): string {
  $error = '';

  $current = staxx_update_queue_read();
  foreach ((array)($current['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true) && !($current['stopped'] ?? false)) {
      $error = 'A queue is already running. Stop it before starting another.';
      return '';
    }
  }

  if ($scope !== 'all' && !staxx_valid_path($scope)) {
    $error = 'Invalid scope.';
    return '';
  }

  $images = (array)staxx_update_state()['images'];
  $items  = [];

  foreach (staxx_folder_layout(staxx_list_stacks()) as $row) {
    if ($row['type'] !== 'stack') continue;
    $stack = $row['stack'];

    if ($scope === 'all') {
      // every stack
    } elseif ($stack['name'] === $scope) {
      // exact stack match
    } elseif (strpos($stack['name'], $scope.'/') === 0) {
      // folder match
    } else {
      continue;
    }

    if (!$includeStopped && !$stack['running']) continue;
    if ($stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    $hasUpdate = false;
    foreach ($meta['services'] as $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;
      if (staxx_updates_pill_for_image($image, $images)['state'] === 'update') { $hasUpdate = true; break; }
    }
    if (!$hasUpdate) continue;

    $items[] = ['stack' => $stack['name'], 'state' => 'waiting', 'job' => '', 'error' => ''];
  }

  if (!$items) {
    $error = 'Nothing in that scope has an update waiting.';
    return '';
  }

  $id = (string)time();
  if (!staxx_update_queue_write([
    'id' => $id, 'scope' => $scope, 'stopped' => false,
    'includeStopped' => $includeStopped, 'items' => $items,
  ])) {
    $error = 'Could not write the update queue.';
    return '';
  }

  return $id;
}

/**
 * Advance the queue by exactly one step: notice a finished running item, then
 * start the next waiting one. Never more than one stack runs at a time.
 * Safe to call repeatedly and concurrently — a lock that cannot be taken
 * simply means someone else is already advancing it, so the current state is
 * returned unchanged rather than waited for.
 */
function staxx_update_queue_tick(): array {
  $lockError = '';
  if (!staxx_update_queue_lock($lockError)) return staxx_update_queue_state();

  $queue = staxx_update_queue_read();
  if (!$queue || !isset($queue['items'])) {
    staxx_update_queue_unlock();
    return staxx_update_queue_state();
  }

  $items   = $queue['items'];
  $changed = false;

  foreach ($items as $i => &$item) {
    if (($item['state'] ?? '') !== 'running') continue;
    $log = staxx_job_log((string)$item['job'], 0);
    if ($log['done']) {
      // A null exit means the job's own log is gone (pruned, or never
      // written) — not the same thing as an update that ran and failed, and
      // the message must not claim otherwise. Marked failed anyway, not
      // done, because there is nothing here to say it actually succeeded.
      if ($log['exit'] === null) {
        $item['state'] = 'failed';
        $item['error'] = 'The update job\'s log is gone, so its outcome could not be recorded. '
                       . 'Check the stack directly.';
      } elseif ($log['exit'] === 0) {
        $item['state'] = 'done';
      } else {
        $item['state'] = 'failed';
        $item['error'] = 'The update failed. Open the log for details.';
      }
      $changed = true;
    }
    break; // only ever one item running
  }
  unset($item);

  $hasRunning = false;
  foreach ($items as $item) if (($item['state'] ?? '') === 'running') $hasRunning = true;

  if (!$hasRunning && !($queue['stopped'] ?? false)) {
    foreach ($items as $i => &$item) {
      if (($item['state'] ?? '') !== 'waiting') continue;

      // The fingerprint of every service in this stack is pushed onto its own
      // history list before the pull runs — the same "before, not after"
      // ordering Part C3 describes, so a roll back has something to roll
      // back to even if the update job itself never finishes cleanly.
      $file = '';
      foreach (staxx_list_stacks() as $s) {
        if ($s['name'] === $item['stack']) { $file = $s['file']; break; }
      }
      if ($file !== '') {
        $meta = staxx_compose_meta($file);
        if ($meta['ok']) {
          foreach ($meta['services'] as $svc => $svcMeta) {
            $image = trim((string)($svcMeta['image'] ?? ''));
            if ($image === '') continue;
            $local = staxx_image_local($image);
            if (!empty($local['digest'])) staxx_update_history_push($item['stack'], $svc, $local['digest']);
          }
        }
      }

      $jobError = '';
      $job = staxx_start_job($item['stack'], 'update', $jobError);
      if ($job === '') {
        $item['state'] = 'failed';
        $item['error'] = $jobError !== '' ? $jobError : 'Could not start the update.';
      } else {
        $item['state'] = 'running';
        $item['job']   = $job;
      }
      $changed = true;
      break; // only ever one item started per tick
    }
    unset($item);
  }

  // Every item finished, one way or another — send the one "applied" message
  // for the whole pass, never one per stack, and only once per queue.
  $terminal = true;
  foreach ($items as $item) {
    if (!in_array($item['state'] ?? '', ['done', 'failed', 'skipped'], true)) { $terminal = false; break; }
  }
  if ($terminal && $changed && empty($queue['notified']) && staxx_update_settings()['notify'] === 'applied') {
    $done = 0; $failed = 0;
    foreach ($items as $item) {
      if ($item['state'] === 'done') $done++;
      elseif ($item['state'] === 'failed') $failed++;
    }
    staxx_update_notify(
      'StaXX image updates applied',
      $done.' stack'.($done === 1 ? '' : 's').' updated'.($failed > 0 ? ', '.$failed.' failed' : '').'.'
    );
    $queue['notified'] = true;
  }

  if ($changed) {
    $queue['items'] = $items;
    staxx_update_queue_write($queue);
  }

  staxx_update_queue_unlock();
  return staxx_update_queue_state();
}

/** Let the running stack finish; mark everything still waiting as skipped. */
function staxx_update_queue_stop(): bool {
  $queue = staxx_update_queue_read();
  if (!$queue || !isset($queue['items'])) return false;

  foreach ($queue['items'] as &$item) {
    if (($item['state'] ?? '') === 'waiting') $item['state'] = 'skipped';
  }
  unset($item);
  $queue['stopped'] = true;

  return staxx_update_queue_write($queue);
}

/**
 * The 15-minute cron pass, and nothing else. Ticks a running queue along;
 * otherwise, when staxx_update_due() has found anything, starts a fresh
 * queue over exactly those stacks. Costs no network either way — every
 * digest it acts on was already fetched by a check pass.
 */
function staxx_update_apply_pass(): array {
  $queue = staxx_update_queue_read();
  $active = false;
  foreach ((array)($queue['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true)) { $active = true; break; }
  }
  if ($active && !($queue['stopped'] ?? false)) return staxx_update_queue_tick();

  $due = staxx_update_due();
  if (!$due) return staxx_update_queue_state();

  // staxx_update_due() answers per service; the queue works a stack at a
  // time, so the due list collapses to its distinct stacks first.
  $wanted = [];
  foreach ($due as $d) $wanted[$d['stack']] = true;

  $items = [];
  foreach (staxx_folder_layout(staxx_list_stacks()) as $row) {
    if ($row['type'] !== 'stack' || !isset($wanted[$row['stack']['name']])) continue;
    $items[] = ['stack' => $row['stack']['name'], 'state' => 'waiting', 'job' => '', 'error' => ''];
  }
  if (!$items) return staxx_update_queue_state();

  staxx_update_queue_write([
    'id' => (string)time(), 'scope' => 'all', 'stopped' => false,
    'includeStopped' => false, 'items' => $items,
  ]);

  return staxx_update_queue_tick();
}

/* ------------------------------------------------------------------ notify -- */

/**
 * One message through Unraid's own notifier — never one per container.
 * Silent whenever the setting is 'off'; a caller that only wants to notify
 * on its own tier (queue completion needs 'applied' specifically, not just
 * 'found') checks staxx_update_settings()['notify'] itself before calling.
 */
function staxx_update_notify(string $subject, string $body): void {
  if (staxx_update_settings()['notify'] === 'off') return;

  staxx_sh(
    '/usr/local/emhttp/webGui/scripts/notify'
      .' -e '.escapeshellarg('StaXX')
      .' -s '.escapeshellarg($subject)
      .' -d '.escapeshellarg($body)
      .' -i '.escapeshellarg('normal'),
    10
  );
}

/* ------------------------------------------------------------ locally built -- */

/**
 * The image named by the final stage's FROM in this service's build recipe,
 * with ARG defaults declared in the recipe substituted. '' when the recipe
 * cannot be found, when the FROM still holds an unresolved variable, or when
 * it names an earlier build stage rather than a real image — none of those
 * can honestly be compared against a registry.
 *
 * Runs `compose config` itself rather than going through
 * staxx_compose_meta(), which only keeps a handful of named fields and throws
 * the rest of the resolved YAML away — the build block was never one of them.
 */
function staxx_build_base(string $stack, string $service): string {
  if (!staxx_valid_path($stack)) return '';

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') return '';

  $cmd = staxx_compose_cmd();
  if ($cmd === '') return '';

  $files = staxx_compose_files($file);
  $code  = 1;
  $yaml  = staxx_sh($cmd.' '.staxx_compose_file_args($files).' config 2>&1', 15, $code);
  if ($code !== 0) return '';

  $prefix = 'services'."\0".$service."\0".'build'."\0";
  $context = '';
  $dockerfile = 'Dockerfile';
  $args = [];

  foreach (staxx_yaml_flatten($yaml) as $path => $value) {
    if (strpos($path, $prefix) !== 0) continue;
    $rest  = substr($path, strlen($prefix));
    $parts = explode("\0", $rest);

    if ($parts[0] === 'context' && count($parts) === 1) $context = $value;
    elseif ($parts[0] === 'dockerfile' && count($parts) === 1) $dockerfile = $value;
    elseif ($parts[0] === 'args' && count($parts) === 2) $args[$parts[1]] = $value;
  }
  if ($context === '') return ''; // this service does not build an image at all

  $ctxDir = $context[0] === '/' ? $context : rtrim($dir, '/').'/'.$context;
  $recipePath = $dockerfile[0] === '/' ? $dockerfile : rtrim($ctxDir, '/').'/'.$dockerfile;

  $recipe = @file_get_contents($recipePath);
  if ($recipe === false) return '';

  // Only ARG defaults declared IN the recipe are ever substituted. A
  // --build-arg supplied only at build time, with no default here, cannot be
  // known without actually running the build, so it is left unresolved on
  // purpose rather than guessed at.
  $argDefaults = [];
  foreach (explode("\n", $recipe) as $line) {
    if (preg_match('/^\s*ARG\s+([A-Za-z_][A-Za-z0-9_]*)(?:=(.*))?\s*$/', $line, $m)) {
      $argDefaults[$m[1]] = trim($m[2] ?? '', "\"' ");
    }
  }
  // Compose's own build args, where set, win over the Dockerfile's defaults —
  // the same precedence an actual build applies.
  $vars = array_merge($argDefaults, $args);

  $stages = [];
  foreach (explode("\n", $recipe) as $line) {
    if (preg_match('/^\s*FROM\s+(\S+)(?:\s+[Aa][Ss]\s+(\S+))?\s*$/', $line, $m)) {
      $stages[] = ['from' => $m[1], 'name' => $m[2] ?? ''];
    }
  }
  if (!$stages) return '';

  $final = end($stages);
  $resolved = preg_replace_callback(
    '/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/',
    function ($m) use ($vars) { return $vars[$m[1]] ?? $m[0]; },
    $final['from']
  );

  if (strpos($resolved, '$') !== false) return ''; // still unresolved
  foreach ($stages as $stage) {
    if ($stage['name'] !== '' && $stage['name'] === $resolved) return ''; // names an earlier stage, not a real image
  }

  return $resolved;
}

/**
 * True when the base a locally-built service is built from has moved on
 * since the last time this was checked. Records the base's current digest
 * the first time nothing is on file yet, and reports false that round —
 * calling a change on the very first look would be a guess dressed up as a
 * finding, not something actually observed.
 */
function staxx_rebuild_due(string $stack, string $service, string &$why): bool {
  $why = '';

  $base = staxx_build_base($stack, $service);
  if ($base === '') {
    $why = "This service's build recipe could not be read, so it cannot be checked.";
    return false;
  }

  $remoteWhy = '';
  $remote = staxx_image_remote($base, $remoteWhy);
  if (empty($remote['digest'])) {
    $why = 'The base image could not be checked.';
    return false;
  }

  $key   = $stack.'::'.$service;
  $state = staxx_update_state();
  $bases = (array)$state['bases'];
  $recorded = (string)($bases[$key] ?? '');

  if ($recorded === '') {
    $bases[$key] = $remote['digest'];
    staxx_update_state_save(['bases' => $bases]);
    return false;
  }

  if ($recorded === $remote['digest']) return false;

  $why = 'The base image this service is built from has moved on. Rebuild to pick it up.';
  return true;
}

/**
 * Record the base image's current registry digest as this service's new
 * baseline, so a rebuild just started stops being reported as due for ever
 * afterwards. Called BEFORE the job starts, not after — the same "before,
 * not after" ordering staxx_update_history_push() uses, since a slow build
 * could otherwise finish after the base has already moved on again. Quietly
 * does nothing when the base cannot be resolved or checked; the next check
 * pass's own call to staxx_rebuild_due() will pick it up once it can.
 */
function staxx_rebuild_baseline_reset(string $stack, string $service): void {
  $base = staxx_build_base($stack, $service);
  if ($base === '') return;

  $why = '';
  $remote = staxx_image_remote($base, $why);
  if (empty($remote['digest'])) return;

  $key   = $stack.'::'.$service;
  $state = staxx_update_state();
  $bases = (array)$state['bases'];
  $bases[$key] = $remote['digest'];
  staxx_update_state_save(['bases' => $bases]);
}
?>
