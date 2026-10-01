<?PHP
/* StaXX — telling the project about Docker Compose messages it cannot explain,
 * and fetching the explanations written since the last release (PLAN_212).
 * Copyright 2026, StaXX contributors.
 *
 * Nothing here ever sends a file, a name or an address: the only thing that
 * leaves the server is a message's "shape" (staxx_compose_shape()), once, and
 * only while Settings, Integrations, "Send Docker errors StaXX cannot explain"
 * is on. The intake is run by the feedback board (see Feedback.php for its
 * address); it may be down, and every failure here is harmless: the shape
 * stays queued and is tried again by the daily pass.
 *
 * The queue lives in <store>/config/error-reports.json: the server ID the
 * intake issued, and each shape with whether it has gone yet.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ComposeErrors.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Feedback.php';

// Where the daily pass fetches the explanations file from (the main branch).
if (!defined('STAXX_COMPOSE_ERRORS_URL')) define('STAXX_COMPOSE_ERRORS_URL',
  'https://raw.githubusercontent.com/quadcom/Staxx/main/src/staxx/usr/local/emhttp/plugins/staxx/include/compose-errors.json');

function staxx_error_reports_file(): string {
  if (defined('STAXX_ERROR_REPORTS_FILE')) return (string)STAXX_ERROR_REPORTS_FILE;   // a suite's scratch file
  $root = staxx_config_root();
  return $root === '' ? '' : $root.'/error-reports.json';
}

/** The queue: ['serverId' => string, 'shapes' => [shape => ['sent' => bool, 'at' => int]]]. */
function staxx_error_reports_load(): array {
  $file = staxx_error_reports_file();
  $data = $file === '' ? null : json_decode((string)@file_get_contents($file), true);
  $shapes = is_array($data['shapes'] ?? null) ? $data['shapes'] : [];
  return ['serverId' => is_string($data['serverId'] ?? null) ? $data['serverId'] : '', 'shapes' => $shapes];
}

function staxx_error_reports_save(array $q): bool {
  $file = staxx_error_reports_file();
  if ($file === '') return false;
  return staxx_atomic_write($file, json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * StaXX's own refusals ("The compose file is empty." and the like) are not
 * Docker Compose's words and are never reported. Judged by how the text
 * starts, which is how every one of them is written; a shape the intake
 * would refuse anyway (outside 10 to 300 characters, or still holding an
 * address) is skipped too.
 */
function staxx_error_report_skips(string $shape): bool {
  $n = strlen($shape);
  if ($n < 10 || $n > 300 || preg_match('/[@\r\n]|https?:/i', $shape)) return true;
  return (bool)preg_match('/^(The compose file is empty|Compose took too long|Compose rejected this file|Could not )/', $shape);
}

/**
 * The reporting line's state for one message: 'off' when the switch is off,
 * '' when an explanation matched (or the message is not Compose's, so there
 * is nothing to report), otherwise 'sent' or 'waiting'. A shape met for the
 * first time is queued and a send is started in the background.
 */
function staxx_error_report_status(string $shape, bool $matched): string {
  if ((staxx_cfg()['ERROR_REPORTS'] ?? 'true') === 'false') return 'off';
  if ($matched || staxx_error_report_skips($shape)) return '';

  $q = staxx_error_reports_load();
  if (isset($q['shapes'][$shape])) return !empty($q['shapes'][$shape]['sent']) ? 'sent' : 'waiting';

  $q['shapes'][$shape] = ['sent' => false, 'at' => time()];
  if (!staxx_error_reports_save($q)) return '';   // no store: nothing can be kept, so nothing is promised
  staxx_error_report_kick();
  return 'waiting';
}

/**
 * Read-only twin of staxx_error_report_status(): 'off' when the switch is
 * off, 'sent' or 'waiting' when the shape is already in the queue, otherwise
 * ''. Queues nothing, so the editor's check can show the line without ever
 * reporting half-typed text.
 */
function staxx_error_report_known(string $shape): string {
  if ((staxx_cfg()['ERROR_REPORTS'] ?? 'true') === 'false') return 'off';
  $info = staxx_error_reports_load()['shapes'][$shape] ?? null;
  return $info === null ? '' : (!empty($info['sent']) ? 'sent' : 'waiting');
}

/** Start the sender without making the request wait for it. */
function staxx_error_report_kick(): void {
  if (defined('STAXX_ERROR_REPORTS_NO_SEND')) return;   // a suite has no network to use
  staxx_detach(
    staxx_php_bin().' -r '.escapeshellarg("require '/usr/local/emhttp/plugins/staxx/include/ErrorReports.php'; staxx_error_report_send();"),
    '/tmp/staxx/error-reports.log'
  );
}

/**
 * Post every waiting shape to the intake. Stops at the first sign the intake
 * is unreachable or busy, leaving the rest waiting. A refusal of one shape
 * (the intake judged it not worth filing) is final for it, so it is not
 * retried for ever. Returns how many were sent.
 */
function staxx_error_report_send(): int {
  if ((staxx_cfg()['ERROR_REPORTS'] ?? 'true') === 'false') return 0;
  $lock = '/tmp/staxx/error-report.lock';
  @mkdir('/tmp/staxx', 0755, true);
  if (!staxx_mkdir_lock_stale($lock, 120)) return 0;   // another send is running

  $sent = 0;
  $facts = staxx_manifest_facts();
  $ver   = preg_match('/^\d{2}\.\d{2}\.\d{2}/', (string)($facts['version'] ?? ''), $m) ? $m[0] : '';
  $comp  = staxx_compose();
  $cver  = preg_match('/\d+\.\d+\.\d+/', (string)($comp['version'] ?? ''), $m) ? $m[0] : '';

  $q = staxx_error_reports_load();
  foreach ($q['shapes'] as $shape => $info) {
    if (!empty($info['sent'])) continue;
    $body = ['shape' => (string)$shape, 'staxxVersion' => $ver];
    if ($cver !== '') $body['composeVersion'] = $cver;
    if ($q['serverId'] !== '') $body['serverId'] = $q['serverId'];

    [$code, $data] = staxx_feedback_call('POST', '/api/apps/staxx/error-report', $body);
    if ($code === 0 || $code === 429 || $code >= 500) break;   // down or busy: try again at the next pass
    if ($code === 200 && !empty($data['ok'])) {
      if (is_string($data['serverId'] ?? null) && $data['serverId'] !== '') $q['serverId'] = $data['serverId'];
      $sent++;
    }
    $q['shapes'][$shape] = ['sent' => true, 'at' => time()];   // other 4xx: refused for good
    staxx_error_reports_save($q);
  }

  @rmdir($lock);
  return $sent;
}

/**
 * The daily fetch of the explanations file into <store>/config/. A fetch that
 * fails, does not parse, or is older than the copy already kept changes
 * nothing. Returns '' on success or when already current, else why not.
 */
function staxx_compose_errors_fetch(): string {
  $root = staxx_config_root();
  if ($root === '') return 'no store';
  $tmp = '/tmp/staxx-compose-errors.'.getmypid().'.json';
  $code = 1;
  staxx_sh('curl -fsSL --max-time 20 -o '.escapeshellarg($tmp).' '.escapeshellarg(STAXX_COMPOSE_ERRORS_URL), 25, $code);
  $new = $code === 0 ? staxx_compose_errors_read($tmp) : null;
  $text = $new === null ? '' : (string)@file_get_contents($tmp);
  @unlink($tmp);
  if ($new === null) return 'the fetch failed or the file did not parse';

  $dest = $root.'/compose-errors.json';
  $have = staxx_compose_errors_read($dest);
  if ($have !== null && strcmp($new['version'], $have['version']) <= 0) return '';
  return staxx_atomic_write($dest, $text) ? '' : 'could not write '.$dest;
}

/** What the daily pass does: fetch the newest explanations, then send what is waiting. */
function staxx_error_reports_daily(): string {
  $why = staxx_compose_errors_fetch();
  staxx_error_report_send();
  return $why;
}
