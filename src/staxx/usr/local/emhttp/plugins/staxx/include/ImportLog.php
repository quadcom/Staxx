<?PHP
/* StaXX — the Import log: what Import saw and did, kept so a failed import in
 * the wild can reach the feedback board with enough to fix it (PLAN_219).
 * Copyright 2026, StaXX contributors.
 *
 * One block per run, newest last, in <store>/config/import.log:
 *
 *   === 2026-10-02T14:03:09+00:00 | StaXX 00.05.00 | Import of 3 rows ===
 *     <one line per fact, each indented two spaces>
 *
 * The indent is what keeps a logged line from being mistaken for a header
 * when the file is split back into runs. File contents are never logged.
 * The last STAXX_IMPORT_LOG_RUNS runs are kept, within STAXX_IMPORT_LOG_MAX bytes.
 *
 * Nothing here may break the reply it is called from: every write is
 * quiet on failure, and loading the file does nothing.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

const STAXX_IMPORT_LOG_RUNS = 5;
const STAXX_IMPORT_LOG_MAX  = 262144;

function staxx_import_log_file(): string {
  if (defined('STAXX_IMPORT_LOG_FILE')) return (string)STAXX_IMPORT_LOG_FILE;   // a suite's scratch file
  $root = staxx_config_root();
  return $root === '' ? '' : $root.'/import.log';
}

/** The run being built: ['head' => string, 'lines' => string[]], or null between runs. */
function &staxx_import_log_state(): ?array {
  static $run = null;
  return $run;
}

function staxx_import_log_begin(string $what): void {
  static $registered = false;
  $run = &staxx_import_log_state();
  if ($run !== null) staxx_import_log_end();   // a run left open is written, not lost
  $version = '';
  if (function_exists('staxx_manifest_facts')) $version = (string)(@staxx_manifest_facts()['version'] ?? '');
  $what = trim(preg_replace('/\s+/', ' ', $what));
  $run = ['head' => '=== '.date('c').' | StaXX '.($version !== '' ? $version : 'unknown').' | '.$what.' ===', 'lines' => []];
  if (!$registered) {
    $registered = true;
    // A run that ends in staxx_fail()/exit never reaches its own end call.
    register_shutdown_function('staxx_import_log_end');
  }
}

function staxx_import_log(string $line): void {
  $run = &staxx_import_log_state();
  if ($run === null) return;
  foreach (preg_split('/\R/', $line) as $one) $run['lines'][] = '  '.rtrim($one);
}

/** Splits the file's text into runs, each starting at its "=== " header. */
function staxx_import_log_split(string $text): array {
  $runs = [];
  foreach (preg_split('/^(?==== )/m', $text, -1, PREG_SPLIT_NO_EMPTY) as $block) {
    if (strncmp($block, '=== ', 4) === 0) $runs[] = rtrim($block)."\n";
  }
  return $runs;
}

function staxx_import_log_end(): void {
  $run = &staxx_import_log_state();
  if ($run === null) return;
  $block = $run['head']."\n".($run['lines'] ? implode("\n", $run['lines'])."\n" : '');
  $run = null;   // cleared first so the shutdown call after an explicit end writes nothing

  $file = staxx_import_log_file();
  if ($file === '') return;
  try {
    // A single run over the cap is cut from its start, keeping the header and a marker.
    if (strlen($block) > STAXX_IMPORT_LOG_MAX) {
      $nl   = (int)strpos($block, "\n");
      $head = substr($block, 0, $nl + 1)."  [earlier lines of this run were cut to keep the log small]\n";
      $tail = substr($block, -(STAXX_IMPORT_LOG_MAX - strlen($head)));
      $cut  = strpos($tail, "\n");   // drop the line the cut landed in
      $block = $head.($cut === false ? '' : substr($tail, $cut + 1));
    }
    $runs = staxx_import_log_split((string)@file_get_contents($file));
    $runs[] = $block;
    $runs = array_slice($runs, -STAXX_IMPORT_LOG_RUNS);
    while (count($runs) > 1 && strlen(implode('', $runs)) > STAXX_IMPORT_LOG_MAX) array_shift($runs);
    @staxx_atomic_write($file, implode('', $runs));
  } catch (\Throwable $e) {
    // Logging is a courtesy; it never breaks what called it.
  }
}

/** The file's text if its newest run is within $days days, else ''. */
function staxx_import_log_recent(int $days = 7): string {
  $file = staxx_import_log_file();
  if ($file === '') return '';
  $text = (string)@file_get_contents($file);
  $runs = staxx_import_log_split($text);
  if (!$runs) return '';
  if (!preg_match('/^=== (\S+) \|/', end($runs), $m)) return '';
  $at = strtotime($m[1]);
  return ($at !== false && $at >= time() - $days * 86400) ? implode('', $runs) : '';
}
?>
