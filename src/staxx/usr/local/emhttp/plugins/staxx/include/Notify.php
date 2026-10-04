<?PHP
/* StaXX — update messages: what they say, when they go, and the summary.
 * Copyright 2026, StaXX contributors.
 *
 * Every message StaXX sends about image updates goes through
 * staxx_notify_events(): the queue tick, the check and the pinned pass hand
 * it events and it decides whether each goes straight away, waits for the
 * daily or weekly summary, or is held for quiet hours. The words live in
 * staxx_notify_text(); the low-level call to Unraid's own notifier is
 * staxx_update_notify() in UpdateRun.php.
 *
 * The summary's waiting events live in <store>/config/notify-digest.json.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';
// staxx_images_human_bytes() and staxx_storage_alert_state(). Images.php loads
// UpdateRun.php, which loads this file; both are guarded, and every call
// between them happens at run time, so the loop is harmless.
require_once '/usr/local/emhttp/plugins/staxx/include/Images.php';

if (defined('STAXX_NOTIFY_LOADED')) return;
define('STAXX_NOTIFY_LOADED', true);

/* ----------------------------------------------------------------- basics -- */

/** The clock every due and quiet-hours check reads; STAXX_NOW lets a suite set it. */
function staxx_notify_now(): int {
  $n = getenv('STAXX_NOW');
  return ($n !== false && ctype_digit($n)) ? (int)$n : time();
}

/** A notification setting, or the plan's default when the key is missing or blank. */
function staxx_notify_opt(string $key): string {
  static $defaults = [
    'UPDATE_NOTIFY_FOUND_WHEN' => 'now', 'UPDATE_NOTIFY_INSTALLED_WHEN' => 'summary',
    'UPDATE_NOTIFY_FAILED_WHEN' => 'now', 'UPDATE_DIGEST_EVERY' => 'day', 'UPDATE_DIGEST_DAY' => '1',
    'UPDATE_DIGEST_TIME' => '08:00', 'UPDATE_NOTIFY_NOTES' => 'lines', 'UPDATE_NOTIFY_ICONS' => 'true',
    'UPDATE_QUIET' => 'false', 'UPDATE_QUIET_START' => '22:00', 'UPDATE_QUIET_END' => '07:00',
    'APP_NOTIFY_RESTARTING_WHEN' => 'now', 'APP_NOTIFY_UNHEALTHY_WHEN' => 'now', 'APP_NOTIFY_STOPPED_WHEN' => 'now',
    'APP_NOTIFY_DOCKER_WHEN' => 'now',
  ];
  // A suite sets STAXX_NOTIFY_OPTS (JSON) to vary settings within one process,
  // since staxx_cfg() reads its files once.
  $o = getenv('STAXX_NOTIFY_OPTS');
  $j = ($o !== false && $o !== '') ? json_decode($o, true) : null;
  if (is_array($j) && isset($j[$key])) return (string)$j[$key];
  $v = trim((string)(staxx_cfg()[$key] ?? ''));
  return $v === '' ? ($defaults[$key] ?? '') : $v;
}

/** "HH:MM" as minutes since midnight; 0 for anything else. */
function staxx_notify_minutes(string $hhmm): int {
  return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) ? (int)$m[1] * 60 + (int)$m[2] : 0;
}

/** Are quiet hours on, and does $now fall inside the window (which may cross midnight)? */
function staxx_notify_quiet(int $now): bool {
  if (staxx_notify_opt('UPDATE_QUIET') !== 'true') return false;
  $from = staxx_notify_minutes(staxx_notify_opt('UPDATE_QUIET_START'));
  $to   = staxx_notify_minutes(staxx_notify_opt('UPDATE_QUIET_END'));
  $at   = (int)date('G', $now) * 60 + (int)date('i', $now);
  if ($from === $to) return false;
  return $from < $to ? ($at >= $from && $at < $to) : ($at >= $from || $at < $to);
}

/* --------------------------------------------------------- failure reasons -- */

/**
 * One plain sentence for why an update failed, from the job log's own Docker
 * error. First matching row of the table wins. $image supplies the registry
 * and tag the sentences name; a log that is gone or holds no error gives the
 * last row's sentence.
 */
function staxx_update_failure_reason(string $log, string $image): string {
  $log = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $log);
  $low = strtolower($log);
  $has = static function (array $needles) use ($low): bool {
    foreach ($needles as $n) if (strpos($low, $n) !== false) return true;
    return false;
  };

  $ref  = preg_replace('/@.*$/', '', $image);
  $parts = explode('/', $ref, 2);
  $host = (count($parts) === 2 && (strpos($parts[0], '.') !== false || strpos($parts[0], ':') !== false
          || $parts[0] === 'localhost')) ? $parts[0] : 'docker.io';
  $hub  = in_array($host, ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true);
  $reg  = $hub ? 'Docker Hub' : $host;
  $last = substr($ref, (int)strrpos($ref, '/'));
  $tag  = strpos($last, ':') !== false ? substr($last, strrpos($last, ':') + 1) : 'latest';

  if ($has(['toomanyrequests', '429 too many requests'])) {
    return $reg."'s download limit was reached.".($hub ? ' It resets within six hours.' : '');
  }
  if ($has(['manifest unknown', 'not found: manifest']) || preg_match('/manifest for .* not found/', $low)) {
    return 'The tag '.$tag.' no longer exists on '.$reg.'. It was removed or renamed.';
  }
  if ($has(['unauthorized', 'denied', 'authentication required'])) {
    return $reg.' refused the download. Check the sign-in for it in StaXX, Settings.';
  }
  if ($has(['no matching manifest for linux/'])) {
    return "There is no build of this image for this server's processor.";
  }
  if ($has(['no such host', 'i/o timeout', 'tls handshake timeout', 'connection refused', 'network is unreachable'])) {
    return $reg.' could not be reached.';
  }
  if ($has(['unexpected eof', 'connection reset by peer'])) {
    return 'The download was cut off part way.';
  }
  if ($has(['500 internal server error', '502 bad gateway', '503 service unavailable', '504 gateway timeout'])) {
    return $reg.' was having trouble.';
  }
  if ($has(['no space left on device'])) {
    return 'The server ran out of disk space during the download.';
  }
  if ($has(['port is already allocated', 'address already in use'])) {
    return preg_match('/:(\d{1,5})\b[^\n]*(?:already allocated|already in use)/i', $log, $m)
      ? 'Port '.$m[1].' is already used by something else.'
      : 'A port is already used by something else.';
  }
  if ($has(['conflict. the container name'])) {
    return preg_match('/container name "\/?([^"]+)"/i', $log, $m)
      ? 'Another container already uses the name '.$m[1].'.'
      : 'Another container already uses that name.';
  }
  if ($has(['exited with code', 'is unhealthy', 'dependency failed to start'])) {
    return 'The app stopped right after it started.';
  }
  foreach (preg_split('/\r?\n/', $log) as $line) {
    $line = trim($line);
    if ($line !== '' && preg_match('/\b(error|failed|cannot|unable)\b/i', $line)) {
      return mb_strlen($line) > 200 ? mb_substr($line, 0, 200) : $line;
    }
  }
  return 'The update stopped before it finished.';
}

/* -------------------------------------------------------------------- text -- */

/**
 * The name a person recognises: the stack's own name (its last path part, since
 * the stored path carries the folders), with the service when it differs.
 */
function staxx_notify_label(array $e): string {
  $stack = (string)($e['stack'] ?? '');
  $svc   = (string)($e['service'] ?? '');
  // A container outside any stack (the app watcher's) has only its name.
  if ($stack === '') {
    foreach ([(string)($e['name'] ?? ''), $svc, (string)($e['image'] ?? '')] as $n) if ($n !== '') return $n;
    return '?';
  }
  $pos  = strrpos($stack, '/');
  $leaf = $pos === false ? $stack : substr($stack, $pos + 1);
  if ($leaf === '') $leaf = $stack;
  return ($svc === '' || strcasecmp($leaf, $svc) === 0) ? $leaf : $leaf.' ('.$svc.')';
}

/** Do the first numbers of the old and new version differ, both being numeric? */
function staxx_notify_is_major(array $e): bool {
  return preg_match('/^v?(\d+)/', (string)($e['was'] ?? ''), $a)
      && preg_match('/^v?(\d+)/', (string)($e['version'] ?? ''), $b)
      && $a[1] !== $b[1];
}

/**
 * "was → new" ($full) or just the new version, falling back to short digests
 * and then to "same tag, new build" when no version can be named.
 */
function staxx_notify_versions(array $e, bool $full): string {
  $was = (string)($e['was'] ?? '');
  $new = (string)($e['version'] ?? '');
  if ($new !== '' && $was !== '' && $was !== $new) return $full ? $was.' → '.$new : $new;
  if ($new !== '' && $was === '') return $new;
  $sh = static fn($d): string => substr(preg_replace('/^sha256:/', '', (string)$d), 0, 12);
  $a = $sh($e['wasDigest'] ?? ''); $b = $sh($e['digest'] ?? '');
  if ($a !== '' && $b !== '' && $a !== $b) return $full ? $a.' → '.$b : $b;
  return 'same tag, new build';
}

/** Markdown reduced to plain text, one line, cut at 120 characters. */
function staxx_notify_plain_line(string $s): string {
  $s = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $s);
  $s = preg_replace('/^\s*(#+\s*|[-*+]\s+|\d+\.\s+|>\s*)/', '', $s);
  $s = trim(str_replace(['**', '__', '`'], '', $s), " \t*_");
  return mb_strlen($s) > 120 ? rtrim(mb_substr($s, 0, 119)).'…' : $s;
}

/** Release notes only count for the version they were fetched for. */
function staxx_notify_notes_ok(array $e): bool {
  return !isset($e['notesFor']) || (string)$e['notesFor'] === (string)($e['version'] ?? '');
}

/**
 * What a message says about one app's release: up to three bullet texts
 * (only when Release notes = lines) and the link, '' when there is none.
 * Shared by the plain-text lines and the HTML email so they cannot disagree.
 *
 * @return array{0:string[], 1:string}
 */
function staxx_notify_note_parts(array $e, string $mode): array {
  if ($mode === 'none' || !staxx_notify_notes_ok($e)) return [[], ''];
  $bullets = [];
  if ($mode === 'lines') {
    foreach (preg_split('/\r?\n/', (string)($e['notes'] ?? '')) as $raw) {
      if (trim($raw) === '') continue;
      $t = staxx_notify_plain_line($raw);
      if ($t !== '') $bullets[] = $t;
      if (count($bullets) === 3) break;
    }
  }
  return [$bullets, (string)($e['notesUrl'] ?? '')];
}

/** The release-note lines under one app in Layouts 1 and 2 (already indented). */
function staxx_notify_note_lines(array $e, string $mode): array {
  [$bullets, $url] = staxx_notify_note_parts($e, $mode);
  $out = array_map(static fn($t) => '   • '.$t, $bullets);
  if ($url !== '') $out[] = $out ? '   • Full release notes ↗ '.$url : '   Release notes ↗ '.$url;
  return $out;
}

/** How many distinct apps a list of events names. */
function staxx_notify_app_count(array $list): int {
  $seen = [];
  foreach ($list as $e) $seen[($e['stack'] ?? '').'/'.($e['service'] ?? '').'/'.($e['image'] ?? '').'/'.($e['name'] ?? '')] = 1;
  return count($seen);
}

/** The kinds the app watcher (PLAN_221) sends: problems with a running app. */
const STAXX_NOTIFY_WATCH = ['restarting', 'unhealthy', 'stopped'];

/** Every kind a summary may hold from the event queue (pinned, look and cleanup are added at send time). */
const STAXX_NOTIFY_DIGEST_KINDS = ['found', 'installed', 'failed', 'restarting', 'unhealthy', 'stopped', 'healthy'];

/** The two kinds about Docker itself (PLAN_223); they name no app and follow APP_NOTIFY_DOCKER_WHEN. */
const STAXX_NOTIFY_DOCKER = ['dockerdown', 'dockerback'];

/** The words after "StaXX: " and the one sentence for a Docker event; count is whole minutes. */
function staxx_notify_docker_words(array $e): array {
  $n    = (int)($e['count'] ?? 0);
  $mins = $n.' '.($n === 1 ? 'minute' : 'minutes');
  if (($e['kind'] ?? '') === 'dockerdown') {
    return ['Docker has stopped answering',
            'Docker has not answered for '.$mins.', so your apps may not be running. Open Settings → Docker and check that Enable Docker is set to Yes, or restart the server.'];
  }
  return ['Docker is back', 'Docker is answering again after '.$mins.'. Apps that are not set to start by themselves may need starting.'];
}

/** The icon and the sentence for one watcher event, e.g. ['🔁', 'Plex has restarted 3 times in the last hour.']. */
function staxx_notify_watch_line(array $e): array {
  if (in_array($e['kind'] ?? '', STAXX_NOTIFY_DOCKER, true)) {
    return [$e['kind'] === 'dockerdown' ? '⚠️' : '💚', staxx_notify_docker_words($e)[1]];
  }
  $name = staxx_notify_label($e);
  $n    = (int)($e['count'] ?? 0);
  $times = $n.' '.($n === 1 ? 'time' : 'times');
  switch ($e['kind'] ?? '') {
    case 'restarting': return ['🔁', $name.' has restarted '.$times.' in the last hour.'];
    case 'unhealthy':  return ['💔', $name.' failed its health check '.$times.' in a row.'];
    case 'stopped':    return ['🛑', trim($name.' stopped. '.(string)($e['reason'] ?? ''))];
    default:           return ['💚', $name.' is healthy again.'];
  }
}

/** The words after "StaXX: " in the subject of a message about running apps: one app's, or a count. */
function staxx_notify_watch_subject(array $list): string {
  if (in_array($list[0]['kind'] ?? '', STAXX_NOTIFY_DOCKER, true)) return staxx_notify_docker_words($list[0])[0];
  $n = staxx_notify_app_count($list);
  if ($n !== 1) return $n.' apps need a look';
  $name = staxx_notify_label($list[0]);
  return $name.(['restarting' => ' keeps restarting', 'unhealthy' => ' is unhealthy'][$list[0]['kind']] ?? ' stopped by itself');
}

/** Up to three distinct names, then "and N more": "plex, sonarr, radarr and 2 more". */
function staxx_notify_cap(array $items): string {
  $items = array_values(array_unique($items));
  if (count($items) <= 3) return implode(', ', $items);
  return implode(', ', array_slice($items, 0, 3)).' and '.(count($items) - 3).' more';
}

/** The capped names of the apps a list of events is about. */
function staxx_notify_names(array $list): string {
  return staxx_notify_cap(array_map('staxx_notify_label', $list));
}

/**
 * The one line under the title when a message is only about running apps. One
 * event: the sentence without the name ("It has restarted 4 times in the last
 * hour."), since the title names the app. Several: one short sentence each.
 */
function staxx_notify_watch_desc(array $problems): string {
  if (in_array($problems[0]['kind'] ?? '', STAXX_NOTIFY_DOCKER, true)) return staxx_notify_docker_words($problems[0])[1];
  if (count($problems) === 1) {
    $e = $problems[0];
    $line = staxx_notify_watch_line($e)[1];
    $rest = substr($line, strlen(staxx_notify_label($e)) + 1);
    if (($e['kind'] ?? '') === 'stopped') {
      $r = trim((string)($e['reason'] ?? ''));
      return $r !== '' ? $r : 'It stopped by itself.';
    }
    return 'It '.$rest;
  }
  $out = [];
  foreach ($problems as $e) {
    $name = staxx_notify_label($e);
    switch ($e['kind'] ?? '') {
      case 'restarting': $out[] = $name.' keeps restarting.'; break;
      case 'unhealthy':  $out[] = $name.' is unhealthy.'; break;
      default:
        $r = (string)($e['reason'] ?? '');
        $short = preg_match('/error code (\d+)/', $r, $m) ? 'error code '.$m[1]
               : (stripos($r, 'out of memory') !== false ? 'out of memory' : '');
        $out[] = $name.' stopped'.($short !== '' ? ': '.$short : '').'.';
    }
  }
  return implode(' ', $out);
}

/**
 * The words of one message from its events. $layout is '1' (an update run
 * finished), '2' (updates found) or '3' (the summary). Besides the update
 * kinds, a summary may carry 'pinned' events, 'look' events ('label',
 * 'detail': a self-test finding) and one 'cleanup' event ('size': bytes).
 *
 * @return array{subject:string, description:string, body:string, importance:string, link:string}
 */
function staxx_notify_text(array $events, string $layout): array {
  $icons = staxx_notify_opt('UPDATE_NOTIFY_ICONS') !== 'false';
  $ic    = static fn(string $s): string => $icons ? $s.' ' : '';
  $mode  = staxx_notify_opt('UPDATE_NOTIFY_NOTES');
  $link  = staxx_view_url().'#updates';
  // A Docker message always travels alone (staxx_notify_events sends it on its own).
  if ($events && in_array($events[0]['kind'] ?? '', STAXX_NOTIFY_DOCKER, true)) {
    [$icon, $line] = staxx_notify_watch_line($events[0]);
    return ['subject' => 'StaXX: '.staxx_notify_docker_words($events[0])[0], 'description' => $line,
            'body' => $ic($icon).$line."\n\nOpen StaXX: ".$link,
            'importance' => $events[0]['kind'] === 'dockerdown' ? 'alert' : 'normal', 'link' => $link];
  }
  $by    = ['installed' => [], 'failed' => [], 'found' => [], 'pinned' => [], 'look' => [], 'cleanup' => [],
            'restarting' => [], 'unhealthy' => [], 'stopped' => [], 'healthy' => []];
  foreach ($events as $e) if (isset($by[$e['kind'] ?? ''])) $by[$e['kind']][] = $e;
  $plural = static fn(int $n, string $w): string => $n.' '.$w.($n === 1 ? '' : 's');
  $apps   = 'staxx_notify_app_count';
  $u = $apps($by['installed']); $f = $apps($by['failed']); $w = $apps($by['found']);
  // Apps with a problem; 'healthy' is only ever good news and never counts here.
  $problems = array_merge($by['restarting'], $by['unhealthy'], $by['stopped']);
  $p = $apps($problems);
  $importance = ($by['restarting'] || $by['stopped']) ? 'alert' : ($f > 0 || $by['unhealthy'] ? 'warning' : 'normal');
  $major = static fn(array $e): string => staxx_notify_is_major($e) ? '   '.$ic('⚠️').'major version' : '';

  if ($layout === '3') {
    $weekly = staxx_notify_opt('UPDATE_DIGEST_EVERY') === 'week';
    $now    = staxx_notify_now();
    $when   = $weekly
      ? 'weekly summary · week of '.date('j M', $now - ((int)date('N', $now) - 1) * 86400)
      : 'daily summary · '.date('j M', $now);
    // Subject and description are words only whatever "Use icons" says: they are
    // the whole message on a phone and in the bell.
    $subject = 'StaXX '.$when;
    $cnt = []; $bits = [];
    if ($u) $cnt[] = $u.' updated';
    if ($w) $cnt[] = $w.' waiting';
    if ($cnt) $bits[] = implode(', ', $cnt).'.';
    if ($f) $bits[] = 'Failed: '.staxx_notify_names($by['failed']).'.';
    if ($p) $bits[] = 'Needs a look: '.staxx_notify_names($problems).'.';
    $description = $bits ? implode(' ', $bits) : 'Nothing new to report.';

    $appList = static function (array $list) use ($mode, $major): array {
      $items = []; $anyUrl = false;
      foreach ($list as $e) {
        $url = ($mode !== 'none' && staxx_notify_notes_ok($e)) ? (string)($e['notesUrl'] ?? '') : '';
        $anyUrl = $anyUrl || $url !== '';
        $items[] = [staxx_notify_label($e).' '.staxx_notify_versions($e, false).$major($e), $url];
      }
      if (!$anyUrl) return ['   '.implode(' · ', array_column($items, 0))];
      return array_map(static fn($i) => '   '.$i[0].($i[1] !== '' ? ' · Release notes ↗ '.$i[1] : ''), $items);
    };
    $out = [];
    if ($u) { $out[] = $ic('✅').'Updated ('.$u.')'; $out = array_merge($out, $appList($by['installed'])); }
    if ($f) {
      $out[] = $ic('❌').'Failed ('.$f.')';
      foreach ($by['failed'] as $e) {
        $out[] = '   '.staxx_notify_label($e).': '.((string)($e['reason'] ?? '') ?: 'The update stopped before it finished.');
      }
    }
    if ($w) { $out[] = $ic('🔔').'Waiting for you ('.$w.')'; $out = array_merge($out, $appList($by['found'])); }
    if ($problems) {
      $out[] = $ic('⚠️').'Apps that need a look ('.$p.')';
      foreach ($problems as $e) $out[] = '   '.staxx_notify_watch_line($e)[1];
    }
    if ($by['healthy']) {
      $out[] = $ic('💚').'Healthy again ('.count($by['healthy']).')';
      foreach ($by['healthy'] as $e) $out[] = '   '.staxx_notify_label($e);
    }
    if ($by['pinned']) {
      $out[] = $ic('📌').'Pinned ('.count($by['pinned']).')';
      foreach ($by['pinned'] as $e) {
        $since = (int)($e['at'] ?? 0);
        $out[] = '   '.staxx_notify_label($e).($since > 0 ? ', pinned since '.date('j M', $since) : '');
      }
    }
    if ($by['look']) {
      $out[] = $ic('⚠️').'Needs a look';
      foreach ($by['look'] as $e) $out[] = '   '.$e['label'].': '.$e['detail'];
    }
    if ($by['cleanup']) {
      $out[] = '';
      $out[] = $ic('💾').staxx_images_human_bytes((int)$by['cleanup'][0]['size']).' of old images can be cleaned up.';
    }
    $out[] = '';
    $out[] = 'Open StaXX: '.$link;
    return ['subject' => $subject, 'description' => $description, 'body' => implode("\n", $out),
            'importance' => $importance, 'link' => $link];
  }

  // Layouts 1 and 2: one line per app, its release notes beneath.
  $lines = [];
  $add = static function (array $list, string $icon) use (&$lines, $ic, $mode, $major): void {
    foreach ($list as $e) {
      $name = staxx_notify_label($e);
      $pad  = str_repeat(' ', max(2, 16 - mb_strlen($name)));
      if (($e['kind'] ?? '') === 'failed') {
        $lines[] = $ic($icon).$name.$pad.((string)($e['reason'] ?? '') ?: 'The update stopped before it finished.');
        continue;
      }
      $size = (int)($e['size'] ?? 0);
      $lines[] = $ic($icon).$name.$pad.staxx_notify_versions($e, true)
               . ($size > 0 ? ' · '.staxx_images_human_bytes($size) : '').$major($e);
      foreach (staxx_notify_note_lines($e, $mode) as $l) $lines[] = $l;
    }
  };

  if ($layout === '2') {
    $add($by['found'], '⬆️');
    $subject = 'StaXX found '.$plural($w, 'update').' waiting';
    $description = staxx_notify_cap(array_map(static fn($e) => staxx_notify_label($e).' '.staxx_notify_versions($e, false)
      .(staxx_notify_is_major($e) ? ' (major version)' : ''), $by['found'])).'.';
    $lines[] = '';
    $lines[] = 'Open StaXX to update them: '.$link;
    return ['subject' => $subject, 'description' => $description, 'body' => implode("\n", $lines),
            'importance' => $importance, 'link' => $link];
  }

  $add($by['installed'], '✅');
  $add($by['failed'], '❌');
  $add($by['found'], '⬆️');
  foreach ($problems as $e) { [$icon, $line] = staxx_notify_watch_line($e); $lines[] = $ic($icon).$line; }
  if ($problems) { $lines[] = ''; $lines[] = 'Open StaXX: '.$link; }
  $head = [];
  if ($u) $head[] = 'StaXX updated '.$plural($u, 'stack');
  if ($f) $head[] = $u ? $f.' failed' : 'StaXX failed to update '.$plural($f, 'stack');
  if ($w) $head[] = $u || $f ? $w.' waiting' : 'StaXX found '.$plural($w, 'update').' waiting';
  if ($p) {
    // Alone, the message is named for the app; beside update news it is one more count.
    $head[] = $u || $f || $w ? $p.($p === 1 ? ' needs' : ' need').' a look' : 'StaXX: '.staxx_notify_watch_subject($problems);
  }
  if (!$u && !$f && !$w) {
    $description = staxx_notify_watch_desc($problems);
  } else {
    $d = [];
    if ($f) $d[] = 'Failed: '.staxx_notify_names($by['failed']).'.';
    if ($u) $d[] = ($f || $w ? 'Updated: ' : '').staxx_notify_names($by['installed']).'.';
    if ($w) $d[] = 'Waiting: '.staxx_notify_names($by['found']).'.';
    if ($p) $d[] = 'Needs a look: '.staxx_notify_names($problems).'.';
    $description = implode(' ', $d);
  }
  return ['subject' => implode(', ', $head), 'description' => $description,
          'body' => implode("
", $lines), 'importance' => $importance, 'link' => $link];
}

/* ------------------------------------------------------------------ sending -- */

/** Does the installed notifier still read the second word of -i? Cached per request. */
function staxx_notify_overrule_ok(bool $reset = false): bool {
  static $ok = null;
  if ($reset) $ok = null;
  if ($ok !== null) return $ok;
  $path = getenv('STAXX_NOTIFY_SCRIPT');
  $path = ($path !== false && $path !== '') ? $path : '/usr/local/emhttp/webGui/scripts/notify';
  $src  = is_file($path) ? @file_get_contents($path) : false;
  return $ok = ($src !== false && strpos($src, '$overrule') !== false);
}

/** Unraid's dynamix.cfg as sections; only [notify] and [ssmtp] are ever looked at, never ssmtp's password file. */
function staxx_notify_dynamix(): array {
  $path = getenv('STAXX_DYNAMIX_CFG');
  $path = ($path !== false && $path !== '') ? $path : '/boot/config/plugins/dynamix/dynamix.cfg';
  return @parse_ini_file($path, true, INI_SCANNER_RAW) ?: [];
}

/** Unraid's delivery bits for one importance (1 bell, 2 email, 4 agents) from [notify] in dynamix.cfg. */
function staxx_notify_bits(string $importance): int {
  $v = staxx_notify_dynamix()['notify'][$importance] ?? null;
  // Raw mode may leave the file's quotes on the value.
  return $v === null ? 1 : ((int)trim((string)$v, " \t\"'") & 7);
}

/** Are any notification agents (Discord, Pushover and so on) set up in Unraid? */
function staxx_notify_agents_present(): bool {
  $dir = getenv('STAXX_NOTIFY_AGENTS');
  $dir = ($dir !== false && $dir !== '') ? $dir : '/boot/config/plugins/dynamix/notifications/agents';
  foreach ((array)@glob(rtrim($dir, '/').'/*') as $f) if (is_file($f)) return true;
  return false;
}

/**
 * The body cut at a line break so it fits an agent (Discord takes 2000
 * characters, Pushover 1024), ending with how many lines were left out.
 */
function staxx_notify_cut_body(string $body, string $link, int $max = 1000): string {
  if (mb_strlen($body) <= $max) return $body;
  $lines = explode("\n", $body);
  $kept = [];
  foreach ($lines as $i => $l) {
    $left = count(array_filter(array_slice($lines, $i), static fn($x) => trim($x) !== ''));
    $tail = "\n…and ".$left.' more. Open StaXX: '.$link;
    if (mb_strlen(implode("\n", array_merge($kept, [$l])).$tail) > $max) {
      return rtrim(implode("\n", $kept)).$tail;
    }
    $kept[] = $l;
  }
  return $body;
}

/* ----------------------------------------------------------- HTML email -- */

/** The Unraid server's name, as the feedback connection reads it. */
function staxx_notify_server_name(): string {
  $dir = getenv('STAXX_NOTIFY_VARDIR');
  $dir = ($dir !== false && $dir !== '') ? $dir : '/var/local/emhttp';
  $n = trim((string)((@parse_ini_file($dir.'/var.ini') ?: [])['NAME'] ?? ''));
  return $n === '' ? 'Unraid server' : mb_substr($n, 0, 80);
}

/** StaXX's absolute address, so the email's button works outside the server's own browser. */
function staxx_notify_base_url(string $server): string {
  $dir = getenv('STAXX_NOTIFY_VARDIR');
  $dir = ($dir !== false && $dir !== '') ? $dir : '/var/local/emhttp';
  $u = rtrim(trim((string)((@parse_ini_file($dir.'/nginx.ini') ?: [])['NGINX_DEFAULTURL'] ?? '')), '/');
  return $u !== '' ? $u : 'http://'.strtolower(preg_replace('/\s+/', '-', $server));
}

/**
 * The Content-ID of an app's icon picture, adding it to $images the first
 * time (one copy per file). Only a PNG, JPEG or GIF under 100 KB that the
 * service names as ./.staxx/<file> goes in; SVG, WebP and ICO (many mail
 * readers do not draw them), a URL, an fa- glyph, no icon or a big file
 * return '' and the caller draws a letter tile instead.
 */
function staxx_notify_icon_cid(array $e, array &$images): string {
  $stack = (string)($e['stack'] ?? '');
  $svc   = (string)($e['service'] ?? '');
  $file  = $stack === '' ? '' : (staxx_stack_compose_map()[$stack] ?? '');
  if ($file === '') return '';
  $meta = staxx_compose_meta($file);
  $icon = (string)(($meta['services'][$svc]['x']['icon'] ?? ''));
  if (!($meta['ok'] ?? false) || !preg_match('~^\./\.staxx/([^/]+)$~', $icon, $m)) return '';
  $ext  = strtolower((string)pathinfo($m[1], PATHINFO_EXTENSION));
  $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][$ext] ?? '';
  $path = $mime === '' ? '' : staxx_icon_serve_path($stack, $m[1]);
  if ($path === '' || filesize($path) > 102400) return '';
  $cid = 'icon-'.md5($path).'@staxx';
  if (!isset($images[$cid])) {
    $data = @file_get_contents($path);
    if ($data === false) return '';
    $images[$cid] = [$mime, $data, basename($path)];
  }
  return $cid;
}

/**
 * The Content-ID of one of StaXX's own small pictures (images/notify/<kind>.png:
 * found, installed, failed, pinned, restarting, unhealthy, stopped, healthy,
 * summary, warning, cleanup), adding it to $images the first time. '' when the
 * file is missing, so the caller leaves the picture out.
 */
function staxx_notify_glyph_cid(string $kind, array &$images): string {
  if (!preg_match('/^[a-z]+$/', $kind)) return '';
  $cid = 'glyph-'.$kind.'@staxx';
  if (!isset($images[$cid])) {
    $data = @file_get_contents(dirname(__DIR__).'/images/notify/'.$kind.'.png');
    if ($data === false) return '';
    $images[$cid] = ['image/png', $data, $kind.'.png'];
  }
  return $cid;
}

/**
 * The HTML email for Layout $layout ('1' run finished, '2' found, '3'
 * summary). Always the light design (Ruling 8); every colour is set on its own
 * element because mail readers drop <style> blocks unevenly, and layout is
 * tables for the same reason.
 *
 * @return array{html:string, images:array<string,array{0:string,1:string,2:string}>}
 *         images: Content-ID => [mime type, bytes, file name]
 */
function staxx_notify_html(array $events, string $layout): array {
  $h     = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $icons = staxx_notify_opt('UPDATE_NOTIFY_ICONS') !== 'false';
  $mode  = staxx_notify_opt('UPDATE_NOTIFY_NOTES');
  $now   = staxx_notify_now();
  $server = staxx_notify_server_name();
  $open  = staxx_notify_base_url($server).staxx_view_url().'#updates';
  $by    = ['installed' => [], 'failed' => [], 'found' => [], 'pinned' => [], 'look' => [], 'cleanup' => [],
            'restarting' => [], 'unhealthy' => [], 'stopped' => [], 'healthy' => []];
  foreach ($events as $e) if (isset($by[$e['kind'] ?? ''])) $by[$e['kind']][] = $e;
  $problems = array_merge($by['restarting'], $by['unhealthy'], $by['stopped']);
  $p = staxx_notify_app_count($problems);
  $u = staxx_notify_app_count($by['installed']); $f = staxx_notify_app_count($by['failed']);
  $w = staxx_notify_app_count($by['found']);
  $plural = static fn(int $n, string $x): string => $n.' '.$x.($n === 1 ? '' : 's');

  $images = [];
  $logo = @file_get_contents(dirname(__DIR__).'/images/staxx.png');
  if ($logo !== false) $images['logo@staxx'] = ['image/png', $logo, 'staxx.png'];

  // One of StaXX's own pictures (with a trailing space) for a heading or mark; nothing when
  // Use icons is off. $alt is the plain word a mail reader shows when it blocks pictures.
  $gl = static function (string $kind, string $alt) use (&$images, $icons): string {
    $cid = $icons ? staxx_notify_glyph_cid($kind, $images) : '';
    return $cid === '' ? '' : '<img src="cid:'.$cid.'" alt="'.$alt.'" width="16" height="16" style="vertical-align:-2px;border:0;margin-right:4px"> ';
  };

  $link = static fn(string $url, string $label): string => preg_match('~^https?://~i', $url)
    ? '<a href="'.$h($url).'" style="color:#d35400;text-decoration:underline">'.$h($label).'&nbsp;↗</a>' : '';
  $li   = 'list-style:disc outside;display:list-item;margin:0 0 2px';

  // Release notes under one app: bullets with the link as the last bullet,
  // or the link on its own line; nothing when there is nothing.
  $notes = static function (array $e, bool $summary) use ($mode, $link, $h, $li): string {
    [$bul, $url] = staxx_notify_note_parts($e, $summary ? ($mode === 'none' ? 'none' : 'link') : $mode);
    $a = $link($url, $bul ? 'Full release notes' : 'Release notes');
    if (!$bul) return $a === '' ? '' : '<div style="margin-top:4px;font-size:14px">'.$a.'</div>';
    $items = array_map(static fn($t) => '<li style="'.$li.'">'.$h($t).'</li>', $bul);
    if ($a !== '') $items[] = '<li style="'.$li.'">'.$a.'</li>';
    return '<ul style="margin:6px 0 2px 18px;padding:0 0 0 4px;font-size:14px;color:#333333;list-style:disc outside">'.implode('', $items).'</ul>';
  };

  $versions = static function (array $e) use ($h, $gl): string {
    $v = explode(' → ', staxx_notify_versions($e, true), 2);
    $s = count($v) === 2 ? $h($v[0]).' → <b style="color:#1a7f37">'.$h($v[1]).'</b>' : $h($v[0]);
    if ((int)($e['size'] ?? 0) > 0) $s .= ' · '.$h(staxx_images_human_bytes((int)$e['size']));
    if (staxx_notify_is_major($e)) {
      $s .= '<span style="color:#a15c00;font-weight:700;font-size:13px;margin-left:6px">'.$gl('warning', 'Warning').'major version</span>';
    }
    return '<div style="font-family:Consolas,Menlo,monospace;font-size:14px;color:#333333">'.$s.'</div>';
  };

  // True right after a heading band: the first row under it draws no top line.
  $afterBand = false;
  $item = static function (array $e, string $inner) use (&$images, &$afterBand, $h): string {
    $line = $afterBand ? '' : 'border-top:1px solid #eeeeee';
    $afterBand = false;
    $name = staxx_notify_label($e);
    $cid  = staxx_notify_icon_cid($e, $images);
    $pic  = $cid !== ''
      ? '<img src="cid:'.$cid.'" width="40" height="40" alt="" style="display:block;width:40px;height:40px;border-radius:8px;background:#f3f3f3">'
      : '<div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:8px;background:#f3f3f3;color:#555555;font-weight:700;font-size:16px">'.$h(mb_strtoupper(mb_substr($name, 0, 1))).'</div>';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="'.$line.'"><tr>'
         . '<td width="54" valign="top" style="padding:12px 14px 12px 0">'.$pic.'</td>'
         . '<td valign="top" style="padding:12px 0"><div style="font-weight:700;font-size:16px;color:#1d1d1f">'.$h($name).'</div>'.$inner.'</td></tr></table>';
  };

  $head = static function (string $t) use (&$afterBand): string {
    $afterBand = true;
    return '<div style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#333333;background:#e4e4e7;padding:9px 12px;border-radius:6px;margin:32px 0 6px">'.$t.'</div>';
  };
  $chip = static fn(string $t, string $bg, string $fg): string => '<span style="display:inline-block;font-size:13px;font-weight:700;padding:4px 12px;border-radius:999px;background:'.$bg.';color:'.$fg.';margin:0 8px 8px 0">'.$h($t).'</span>';
  $reason = static fn(array $e): string => '<div style="color:#c62828;font-size:14px">'.$h((string)($e['reason'] ?? '') ?: 'The update stopped before it finished.').'</div>';
  // The watcher's sentence under the app's name, without the name again ("Restarted 3 times in the
  // last hour."): red for restarting and stopped, amber for unhealthy, green for healthy.
  $watch = static function (array $e) use ($h): string {
    $colour = ['unhealthy' => '#a15c00', 'healthy' => '#1a7f37'][$e['kind']] ?? '#c62828';
    $t = substr(staxx_notify_watch_line($e)[1], strlen(staxx_notify_label($e)) + 1);
    $t = $t === 'is healthy again.' ? 'Healthy again.' : ucfirst(preg_replace('/^has /', '', $t));
    return '<div style="color:'.$colour.';font-size:14px">'.$h($t).'</div>';
  };
  $sum = $layout === '3';
  $rows = static function (array $list, callable $inner) use ($item): string {
    $o = '';
    foreach ($list as $e) $o .= $item($e, $inner($e));
    return $o;
  };
  $withNotes = static fn(array $e): string => $versions($e).$notes($e, $sum);

  // Title, subtitle and the chips.
  $chips = '';
  if ($u) $chips .= $chip($u.' updated', '#e3f5e8', '#1a7f37');
  if ($f) $chips .= $chip($f.' failed', '#fde7e7', '#c62828');
  if ($w) $chips .= $chip($w.' waiting', '#fff1db', '#a15c00');
  if ($by['pinned']) $chips .= $chip(count($by['pinned']).' pinned', '#e8eefc', '#2f55c4');
  foreach ([['restarting', '#fde7e7', '#c62828'], ['unhealthy', '#fff1db', '#a15c00'],
            ['stopped', '#fde7e7', '#c62828'], ['healthy', '#e3f5e8', '#1a7f37']] as [$k, $bg, $fg]) {
    if ($by[$k]) $chips .= $chip(staxx_notify_app_count($by[$k]).' '.$k, $bg, $fg);
  }
  // A Docker message (PLAN_223) is alone: its subject as the title, its sentence as the body.
  $dock = $events && in_array($events[0]['kind'] ?? '', STAXX_NOTIFY_DOCKER, true) ? $events[0] : null;
  if ($dock) {
    $title = staxx_notify_docker_words($dock)[0];
    $sub   = $server.' · '.date('j M, H:i', $now);
  } elseif ($sum) {
    $weekly = staxx_notify_opt('UPDATE_DIGEST_EVERY') === 'week';
    $title  = $weekly ? 'Weekly summary · week of '.date('j M', $now - ((int)date('N', $now) - 1) * 86400)
                      : 'Daily summary · '.date('j M', $now);
    $sub = $server;
  } else {
    $parts = [];
    if ($p) $parts[] = $u || $f || $w ? $p.' need a look' : staxx_notify_watch_subject($problems);
    if ($u) $parts[] = $plural($u, 'stack').' updated';
    if ($f) $parts[] = $u ? $f.' failed' : $plural($f, 'stack').' failed to update';
    if ($w) $parts[] = $u || $f ? $w.' waiting' : $plural($w, 'update').' waiting for you';
    $title = implode(', ', $parts);
    $sub = $server.' · '.date('j M, H:i', $now);
  }

  $body = '<div>'.$chips.'</div>';
  $multi = $layout !== '2';
  if ($dock) {
    $down = $dock['kind'] === 'dockerdown';
    $body .= '<div style="font-size:15px;color:'.($down ? '#c62828' : '#1a7f37').'">'
           . $gl($down ? 'warning' : 'healthy', $down ? 'Docker is down' : 'Docker is back').$h(staxx_notify_docker_words($dock)[1]).'</div>';
  }
  if ($by['installed']) $body .= ($multi ? $head($gl('installed', 'Updated').'Updated') : '').$rows($by['installed'], $withNotes);
  if ($by['failed'])    $body .= ($multi ? $head($gl('failed', 'Failed').'Failed') : '').$rows($by['failed'], $reason);
  if ($by['found'])     $body .= ($multi && ($by['installed'] || $by['failed'] || $sum) ? $head($gl('found', 'Waiting for you').'Waiting for you') : '').$rows($by['found'], $withNotes);
  if ($problems) {
    if ($by['installed'] || $by['failed'] || $by['found'] || $sum || $p > 1) $body .= $head($gl('warning', 'Needs a look').'Needs a look');
    foreach ($problems as $e) $body .= $item($e, $watch($e));
  }
  if ($by['healthy']) $body .= $head($gl('healthy', 'Healthy again').'Healthy again').$rows($by['healthy'], $watch);
  if ($by['pinned']) {
    $body .= $head($gl('pinned', 'Pinned').'Pinned').$rows($by['pinned'], static function (array $e) use ($h): string {
      $at = (int)($e['at'] ?? 0);
      return '<div style="font-family:Consolas,Menlo,monospace;font-size:14px;color:#333333">'.($at > 0 ? 'pinned since '.$h(date('j M', $at)) : 'still pinned').'</div>';
    });
  }
  if ($by['look']) {
    $body .= $head($gl('warning', 'Needs a look').'Needs a look');
    foreach ($by['look'] as $e) $body .= '<div style="font-size:14px;color:#333333;padding:4px 0">'.$h($e['label'].': '.$e['detail']).'</div>';
  }
  if ($by['cleanup']) {
    $body .= '<div style="margin-top:16px;font-size:14px;color:#333333">'.$gl('cleanup', 'Old images').$h(staxx_images_human_bytes((int)$by['cleanup'][0]['size']))
           . ' of old images can be cleaned up. '.$link($open, 'Clean up images').'</div>';
  }
  $button = ($layout === '2' ? 'Update them in StaXX' : 'Open StaXX');
  $body .= '<div style="margin:16px 0 20px"><a href="'.$h($open).'" style="display:inline-block;background:#ff8c2f;color:#ffffff;text-decoration:none;font-weight:700;padding:10px 20px;border-radius:6px">'.$button.'</a></div>';

  $logoTag = $logo === false ? '' : '<td width="48" valign="middle" style="padding-right:12px"><img src="cid:logo@staxx" width="36" height="36" alt="StaXX" style="display:block;width:36px;height:36px"></td>';
  $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$h($title).'</title></head>'
    . '<body style="margin:0;padding:0;background:#f4f4f5">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5"><tr><td align="center" style="padding:20px 10px">'
    . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:100%;background:#ffffff;border-radius:10px;font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d1d1f">'
    . '<tr><td style="background:#1f1f1f;padding:18px 24px;border-radius:10px 10px 0 0"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'.$logoTag
    . '<td valign="middle"><div style="font-size:18px;font-weight:700;color:#ffffff">'.$h($title).'</div><div style="font-size:13px;color:#cccccc">'.$h($sub).'</div></td></tr></table></td></tr>'
    . '<tr><td style="padding:20px 24px 8px">'.$body.'</td></tr>'
    . '<tr><td style="background:#fafafa;border-top:1px solid #eeeeee;padding:14px 24px;font-size:12.5px;color:#666666;border-radius:0 0 10px 10px">Sent by StaXX on '.$h($server).'. Change these emails in StaXX, Settings, Updates.</td></tr>'
    . '</table></td></tr></table></body></html>';
  return ['html' => $html, 'images' => $images];
}

/** A header value on one line, so config text can never add a header. */
function staxx_notify_oneline(string $s): string {
  return trim(preg_replace('/[\r\n]+/', ' ', $s));
}

/**
 * Send the HTML email through PHP's mail(), the route Unraid's own notify uses
 * (ssmtp underneath), to the address set in Unraid's notification settings.
 * Does nothing when no address is set. STAXX_MAIL_FILE makes a suite write the
 * whole message to a file instead of sending anything.
 *
 * Body: multipart/related holding multipart/alternative (the plain text, then
 * the HTML) and the pictures the HTML points at by cid:.
 */
function staxx_notify_send_html(string $subject, string $text, array $events, string $layout, string $importance): void {
  $ssmtp = (array)(staxx_notify_dynamix()['ssmtp'] ?? []);
  $val   = static fn(string $k): string => staxx_notify_oneline(trim((string)($ssmtp[$k] ?? ''), " \t\"'"));
  $to    = implode(',', preg_split('/\s+/', $val('RcptTo'), -1, PREG_SPLIT_NO_EMPTY));
  if ($to === '') return;

  $mail = staxx_notify_html($events, $layout);
  $prefix = $val('Subject');
  $subj = mb_encode_mimeheader(($prefix !== '' ? rtrim($prefix).' ' : '').$subject, 'UTF-8', 'B', "\n");
  $b1 = '=_staxx_r_'.bin2hex(random_bytes(8));
  $b2 = '=_staxx_a_'.bin2hex(random_bytes(8));
  $b64 = static fn(string $s): string => rtrim(chunk_split(base64_encode($s), 76, "\n"));

  $headers = ['MIME-Version: 1.0'];
  $from = $val('root');
  if ($from !== '') { $headers[] = 'From: '.$from; $headers[] = 'Reply-To: '.$from; }
  if ($importance !== 'normal' && strtolower($val('SetEmailPriority')) === 'true') {
    $headers[] = 'X-Priority: 1 (highest)';
    $headers[] = 'X-Mms-Priority: High';
  }
  $headers[] = 'Content-Type: multipart/related; boundary="'.$b1.'"';

  $msg  = "--$b1\nContent-Type: multipart/alternative; boundary=\"$b2\"\n\n";
  $msg .= "--$b2\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n".$b64($text)."\n";
  $msg .= "--$b2\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n".$b64($mail['html'])."\n";
  $msg .= "--$b2--\n";
  foreach ($mail['images'] as $cid => [$mime, $data, $name]) {
    $msg .= "--$b1\nContent-Type: $mime; name=\"$name\"\nContent-Transfer-Encoding: base64\nContent-ID: <$cid>\n"
          . "Content-Disposition: inline; filename=\"$name\"\n\n".$b64($data)."\n";
  }
  $msg .= "--$b1--\n";

  $file = getenv('STAXX_MAIL_FILE');
  if ($file !== false && $file !== '') {
    @file_put_contents($file, "To: $to\nSubject: $subj\n".implode("\n", $headers)."\n\n".$msg);
    return;
  }
  @mail($to, $subj, $msg, implode("\n", $headers));
}

/**
 * Deliver one message. $events and $layout ('1', '2' or '3') are what it was
 * built from; the HTML route needs them, the text route does not.
 *
 * With advanced email on (and an Unraid that honours the overrule), StaXX
 * sends its own HTML email when the user's bits for this importance include
 * email, then hands the plain text on with the email bit cleared so the bell
 * and every agent still get it.
 */
function staxx_notify_send(string $subject, string $description, string $body, string $link,
                           string $importance, array $events = [], string $layout = '1'): void {
  $bits = staxx_notify_bits($importance);
  $imp  = $importance;                       // what the text route passes to notify
  if (staxx_notify_opt('UPDATE_NOTIFY_HTML') === 'true' && staxx_notify_overrule_ok()) {
    if ($bits & 2) staxx_notify_send_html($subject, $body, $events, $layout, $importance);
    $bits &= ~2;
    if ($bits === 0) return;
    $imp = $importance.' '.$bits;
  }

  if (!staxx_notify_agents_present()) {
    staxx_update_notify($subject, $description, $body, $link, $imp);
    return;
  }
  $cut = staxx_notify_cut_body($body, $link);
  if ($cut === $body || !($bits & 4)) {
    staxx_update_notify($subject, $description, $body, $link, $imp);
  } elseif (!staxx_notify_overrule_ok()) {
    staxx_update_notify($subject, $description, $cut, $link, $imp);
  } else {
    staxx_update_notify($subject, $description, $cut, $link, $importance.' 4');
    if ($bits & 3) staxx_update_notify($subject, $description, $body, $link, $importance.' '.($bits & 3));
  }
}

/* ------------------------------------------------------------------ digest -- */

function staxx_notify_digest_file(): string {
  $p = getenv('STAXX_NOTIFY_DIGEST');
  if ($p !== false && $p !== '') return $p;
  $root = staxx_store_root();
  return $root === '' ? '' : $root.'/config/notify-digest.json';
}

function staxx_notify_digest_read(): array {
  $default = ['sentAt' => 0, 'events' => [], 'pinned' => [], 'pinnedDue' => false];
  $file = staxx_notify_digest_file();
  $raw  = $file === '' ? false : @file_get_contents($file);
  $data = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? array_merge($default, $data) : $default;
}

/**
 * Read, change and write the digest file as one step under its lock.
 * $fn takes the data by reference. False when there is no store or the
 * lock could not be had (nothing is written then).
 */
function staxx_notify_digest_edit(callable $fn): bool {
  $file = staxx_notify_digest_file();
  if ($file === '') return false;
  $lock = '/tmp/staxx/notify-digest.lock';
  $got = false;
  for ($i = 0; $i < 20; $i++) {
    if (staxx_mkdir_lock_stale($lock, 60)) { $got = true; break; }
    usleep(100000);
  }
  if (!$got) return false;
  $data = staxx_notify_digest_read();
  $fn($data);
  @mkdir(dirname($file), 0755, true);
  $ok = staxx_atomic_write($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  @rmdir($lock);
  return $ok;
}

/** Add events to the digest; a newer event for the same app and kind replaces the older one. */
function staxx_notify_digest_add(array $events, bool $held): void {
  staxx_notify_digest_edit(static function (array &$d) use ($events, $held): void {
    foreach ($events as $e) {
      $e['held'] = $held;
      $key = static fn(array $x): string => ($x['kind'] ?? '').'|'.($x['stack'] ?? '').'|'.($x['service'] ?? '').'|'.($x['name'] ?? '');
      $d['events'] = array_values(array_filter($d['events'], static fn($o) => $key($o) !== $key($e)));
      $d['events'][] = $e;
    }
  });
}

/**
 * The pinned pass hands its current list here (events of kind 'pinned':
 * 'stack', 'service', 'image', 'at' = pinned since). The summary carries it
 * on its next send; the pinned pass keeps its own weekly clock.
 */
function staxx_notify_pinned_due(array $events): void {
  staxx_notify_digest_edit(static function (array &$d) use ($events): void {
    $d['pinned'] = array_values($events);
    $d['pinnedDue'] = $events !== [];
  });
}

/**
 * Route events: summary ones (and straight-away ones in quiet hours, failures
 * excepted) go to the digest; the rest leave at once as one message together
 * with anything quiet hours held back.
 */
function staxx_notify_events(array $events): void {
  $now  = staxx_notify_now();
  $send = [];
  $pinned = [];
  foreach ($events as $e) {
    $kind = (string)($e['kind'] ?? '');
    if ($kind === 'pinned') { $pinned[] = $e; continue; }
    // Docker itself (PLAN_223): straight away on its own, never held for quiet hours and
    // never in the summary; a day-late "Docker was down" is no use.
    if (in_array($kind, STAXX_NOTIFY_DOCKER, true)) {
      if (staxx_notify_opt('APP_NOTIFY_DOCKER_WHEN') === 'off') continue;
      $t = staxx_notify_text([$e], '1');
      staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], [$e], '1');
      continue;
    }
    // The app watcher's kinds (PLAN_221). Like 'failed' they are never held for
    // quiet hours; 'healthy' only ever rides in the summary, and not at all
    // when the unhealthy messages are off.
    if (in_array($kind, STAXX_NOTIFY_WATCH, true)) {
      $when = staxx_notify_opt('APP_NOTIFY_'.strtoupper($kind).'_WHEN');
      if ($when === 'off') continue;
      if ($when === 'summary') staxx_notify_digest_add([$e], false); else $send[] = $e;
      continue;
    }
    if ($kind === 'healthy') {
      if (staxx_notify_opt('APP_NOTIFY_UNHEALTHY_WHEN') !== 'off') staxx_notify_digest_add([$e], false);
      continue;
    }
    if (!in_array($kind, ['found', 'installed', 'failed'], true)) continue;
    // PLAN_227: Settings' Off writes UPDATE_NOTIFY_<KIND>=false, so an event only
    // reaches here then because an app's own tick overrode it. That is sent
    // straight away (quiet hours still hold it, as for any 'now' event), not at
    // the stale _WHEN left from before Off was pressed. A missing key is '', not off.
    $off = staxx_notify_opt('UPDATE_NOTIFY_'.strtoupper($kind)) === 'false';
    if (!$off && staxx_notify_opt('UPDATE_NOTIFY_'.strtoupper($kind).'_WHEN') === 'summary') {
      staxx_notify_digest_add([$e], false);
    } elseif ($kind !== 'failed' && staxx_notify_quiet($now)) {
      staxx_notify_digest_add([$e], true);
    } else {
      $send[] = $e;
    }
  }
  if ($pinned) staxx_notify_pinned_due($pinned);
  if (!$send) return;

  if (!staxx_notify_quiet($now)) {
    // Outside quiet hours: this message carries what was held back.
    staxx_notify_digest_edit(static function (array &$d) use (&$send): void {
      foreach ($d['events'] as $o) if (!empty($o['held'])) $send[] = $o;
      $d['events'] = array_values(array_filter($d['events'], static fn($o) => empty($o['held'])));
    });
  }
  $layout = array_filter($send, static fn($e) => $e['kind'] !== 'found') ? '1' : '2';
  $t = staxx_notify_text($send, $layout);
  staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $send, $layout);
}

/** The most recent moment the summary was due, or 0. */
function staxx_notify_digest_due_at(int $now): int {
  $mins   = staxx_notify_minutes(staxx_notify_opt('UPDATE_DIGEST_TIME'));
  $weekly = staxx_notify_opt('UPDATE_DIGEST_EVERY') === 'week';
  $dow    = (int)staxx_notify_opt('UPDATE_DIGEST_DAY');
  for ($back = 0; $back <= 7; $back++) {
    $t = mktime(intdiv($mins, 60), $mins % 60, 0, (int)date('n', $now), (int)date('j', $now) - $back, (int)date('Y', $now));
    if ($t > $now) continue;
    if (!$weekly || (int)date('w', $t) === $dow) return $t;
  }
  return 0;
}

/** Self-test findings marked bad, as summary 'look' events. */
function staxx_notify_looks(): array {
  $out = [];
  foreach (staxx_selftest() as $label => $e) {
    if (($e['state'] ?? '') === 'bad') $out[] = ['kind' => 'look', 'label' => (string)$label, 'detail' => (string)$e['detail']];
  }
  return $out;
}

/**
 * Send the summary if it is due. Run from the 15-minute apply pass. A missed
 * send time goes at the next pass; a quiet day sends nothing and still moves
 * sentAt. The pinned list rides along only when the pinned pass marked it
 * due; "Needs a look" and the old-images line only beside other content.
 */
function staxx_notify_digest_pass(): void {
  $now = staxx_notify_now();
  $dueAt = staxx_notify_digest_due_at($now);
  if ($dueAt === 0 || staxx_notify_digest_file() === '') return;
  $d = staxx_notify_digest_read();
  if ((int)$d['sentAt'] >= $dueAt) return;

  $taken = null;
  $ok = staxx_notify_digest_edit(static function (array &$data) use (&$taken, $now, $dueAt): void {
    if ((int)$data['sentAt'] >= $dueAt) return;   // another pass got there first
    $taken = $data;
    $data['events'] = [];
    $data['sentAt'] = $now;
    if (array_filter($taken['events'], static fn($e) => in_array($e['kind'] ?? '', STAXX_NOTIFY_DIGEST_KINDS, true))) {
      $data['pinnedDue'] = false;
    }
  });
  if (!$ok || $taken === null) return;

  $events = array_values(array_filter($taken['events'], static fn($e) => in_array($e['kind'] ?? '', STAXX_NOTIFY_DIGEST_KINDS, true)));
  if (!$events) return;                            // a quiet day

  if (!empty($taken['pinnedDue'])) $events = array_merge($events, $taken['pinned']);
  $events = array_merge($events, staxx_notify_looks());
  $clutter = (int)(staxx_storage_alert_state()['clutterBytes'] ?? 0);
  if ($clutter >= 1073741824) $events[] = ['kind' => 'cleanup', 'size' => $clutter];

  $t = staxx_notify_text($events, '3');
  staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $events, '3');
}

/* -------------------------------------------------------------------- test -- */

/**
 * "Send a test message": Layout 1 from the newest image-history entries (up
 * to three apps) or sample apps when there are none, through the normal
 * route with the current settings and ignoring _WHEN, quiet hours and the
 * on/off switches.
 *
 * @return array{ok:bool, message:string}
 */
function staxx_notify_test(): array {
  $bin = getenv('STAXX_NOTIFY_BIN');
  $bin = ($bin !== false && $bin !== '') ? $bin : '/usr/local/emhttp/webGui/scripts/notify';
  if (!is_file($bin)) {
    return ['ok' => false, 'message' => "Unraid's notification program was not found, so no message could be sent."];
  }
  $found = [];
  foreach (array_keys(staxx_stack_compose_map()) as $rel) {
    foreach ((array)(staxx_record_read($rel)['images'] ?? []) as $svc => $list) {
      if (!isset($list[0]['at'])) continue;
      // The history records the build each pull REPLACED, so [0] is the old
      // version; the new one is read from the image in use, below.
      $found[] = ['kind' => 'installed', 'stack' => $rel, 'service' => (string)$svc, 'at' => (int)$list[0]['at'],
                  'was' => (string)($list[0]['version'] ?? ''), 'wasDigest' => (string)($list[0]['digest'] ?? '')];
    }
  }
  usort($found, static fn($a, $b) => $b['at'] <=> $a['at']);
  $events = array_slice($found, 0, 3);
  foreach ($events as &$ev) {
    $cf = staxx_stack_compose_map()[$ev['stack']] ?? '';
    $image = $cf === '' ? '' : trim((string)(staxx_compose_meta($cf)['services'][$ev['service']]['image'] ?? ''));
    if ($image === '') continue;
    // One local inspect: the version labels (same ones the history uses) and the size on disk.
    $code = 1;
    $out = trim(staxx_sh(staxx_docker_bin().' image inspect '.escapeshellarg($image).' --format '
      .escapeshellarg('{{json .Config.Labels}}|{{.Size}}'), 8, $code));
    $bar = strrpos($out, '|');
    if ($code !== 0 || $bar === false) continue;
    $labels = json_decode(substr($out, 0, $bar), true);
    $ev['version'] = (string)(staxx_update_labels_meta(is_array($labels) ? $labels : [])['version'] ?? '');
    $ev['size'] = (int)substr($out, $bar + 1);
  }
  unset($ev);
  if (!$events) {
    $events = [
      ['kind' => 'installed', 'stack' => 'jellyfin', 'service' => 'jellyfin', 'was' => '10.9.11', 'version' => '10.10.3',
       'notes' => "Adds HDR tone mapping for Intel Arc\nFixes subtitles stuck on screen after seeking",
       'notesUrl' => 'https://github.com/jellyfin/jellyfin/releases/tag/v10.10.3'],
      ['kind' => 'installed', 'stack' => 'sonarr', 'service' => 'sonarr', 'was' => '4.0.9', 'version' => '4.0.10'],
      ['kind' => 'failed', 'stack' => 'immich', 'service' => 'immich',
       'reason' => "Docker Hub's download limit was reached. It resets within six hours."],
    ];
  }
  $t = staxx_notify_text($events, '1');
  staxx_notify_send('Test: '.$t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $events);
  return ['ok' => true, 'message' => 'A test message was sent.'];
}
?>
