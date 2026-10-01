<?PHP
/* StaXX — explaining a message Docker Compose gives when it refuses a file.
 * Copyright 2026, StaXX contributors.
 *
 * Compose's messages are written for people who already know compose. The
 * look-up list in compose-errors.json turns the common ones into plain
 * sentences, and this file is the one place that matches a message against
 * it, so the editor's check, a failed save and an import all get the same
 * answer and the browser only draws it.
 *
 * Two copies of the list can exist: the one shipped in every build, and a
 * newer one fetched into the store's config folder (PLAN_212). The copy with
 * the later "version" wins; a store copy that is missing or does not parse is
 * ignored, so a bad fetch can never take the explanations away.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

if (function_exists('staxx_compose_explain')) return;

/**
 * Read one explanations file: its version and its entries, or null when the
 * file is missing, does not parse, or holds no usable entry. An entry without
 * an id and a pattern is skipped rather than failing the whole file.
 */
function staxx_compose_errors_read(string $path): ?array {
  if ($path === '' || !@is_file($path)) return null;
  $data = json_decode((string)@file_get_contents($path), true);
  if (!is_array($data) || !is_string($data['version'] ?? null) || !is_array($data['entries'] ?? null)) return null;
  $entries = [];
  foreach ($data['entries'] as $e) {
    if (is_array($e) && is_string($e['id'] ?? null) && is_string($e['match'] ?? null)) $entries[] = $e;
  }
  return $entries === [] ? null : ['version' => $data['version'], 'entries' => $entries];
}

/**
 * The entries to match against: whichever of the shipped file and the store's
 * fetched copy has the later version string (dates, so text order is date
 * order). The two constants let a suite point at scratch files.
 */
function staxx_compose_errors_entries(): array {
  $shipped = defined('STAXX_COMPOSE_ERRORS_SHIPPED') ? STAXX_COMPOSE_ERRORS_SHIPPED
           : dirname(__FILE__).'/compose-errors.json';
  $cfg     = staxx_config_root();
  $store   = defined('STAXX_COMPOSE_ERRORS_STORE') ? STAXX_COMPOSE_ERRORS_STORE
           : ($cfg === '' ? '' : $cfg.'/compose-errors.json');

  $a = staxx_compose_errors_read($shipped);
  $b = staxx_compose_errors_read($store);
  if ($a === null) return $b['entries'] ?? [];
  if ($b === null) return $a['entries'];
  return strcmp($b['version'], $a['version']) > 0 ? $b['entries'] : $a['entries'];
}

/**
 * Compose's message with the parts that vary taken out, so the same fault on
 * two servers gives the same text and nothing private leaves the machine.
 * The rules run in this order (PLAN_212): an absolute path, services.<name>,
 * service "<name>", anything quoted, an IP address. A "line N" keeps its number.
 */
function staxx_compose_shape(string $raw): string {
  $s = trim($raw);
  $s = preg_replace('~(?<![\w.<])/[\w.\-/]+~', '<path>', $s);
  $s = preg_replace('~\bservices\.[^.\s:\'"]+~', 'services.<service>', $s);
  $s = preg_replace('~\bservice "[^"]*"~', 'service "<service>"', $s);
  // The service rule above has just put quotes of its own into the text; they
  // must not be turned into a value in turn.
  $s = preg_replace_callback('~\'[^\']*\'|"[^"]*"~', function ($m) {
    return $m[0] === '"<service>"' ? $m[0] : "'<value>'";
  }, $s);
  $s = preg_replace('~\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b~', '<address>', $s);
  $s = preg_replace('~\b(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}\b~', '<address>', $s);
  return $s;
}

/**
 * Explain one message. Always returns the same four keys:
 *   raw    compose's own message, as given
 *   shape  staxx_compose_shape() of it
 *   entry  null, or id/title/means/fix/docs/autofix with {name} filled in as
 *          plain text (the browser escapes it; never HTML here)
 *   report the reporting line's state ('' unless reporting is installed)
 * Only $queue = true may queue an unknown shape for sending: the editor's
 * check runs on half-typed text, whose transient errors must never reach the
 * board. Without it, report is only what an earlier queued call decided.
 */
function staxx_compose_explain(string $raw, bool $queue = false): array {
  $shape = staxx_compose_shape($raw);
  // "validating <path>: " is compose saying which file, which the person
  // already knows; the patterns are written without it.
  $body  = preg_replace('~^\s*validating\s+\S+?:\s+~', '', trim($raw));
  $entry = null;

  foreach (staxx_compose_errors_entries() as $e) {
    $re = '~'.str_replace('~', '\~', $e['match']).'~u';
    if (@preg_match($re, $body, $m) !== 1) continue;
    $fill = function (string $t) use ($m): string {
      return (string)preg_replace_callback('~\{(\w+)\}~', function ($g) use ($m) {
        return isset($m[$g[1]]) && is_string($m[$g[1]]) ? $m[$g[1]] : $g[0];
      }, $t);
    };
    $entry = [
      'id'      => (string)$e['id'],
      'title'   => $fill((string)($e['title'] ?? '')),
      'means'   => $fill((string)($e['means'] ?? '')),
      'fix'     => $fill((string)($e['fix'] ?? '')),
      'docs'    => (string)($e['docs'] ?? ''),
      'autofix' => (string)($e['autofix'] ?? ''),
    ];
    break;
  }

  $report = '';
  if ($queue && function_exists('staxx_error_report_status')) {
    $report = (string)staxx_error_report_status($shape, $entry !== null);
  } elseif (!$queue && $entry === null && function_exists('staxx_error_report_known')) {
    $report = (string)staxx_error_report_known($shape);
  }
  return ['raw' => $raw, 'shape' => $shape, 'entry' => $entry, 'report' => $report];
}
