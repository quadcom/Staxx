<?PHP
/* Stack Manager — compose-native Docker management for Unraid.
 * Copyright 2026, Stack Manager contributors.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
if (defined('STACKMAN_PLUGIN')) return;

define('STACKMAN_PLUGIN',  'stack.manager');
define('STACKMAN_ROOT',    '/usr/local/emhttp/plugins/'.STACKMAN_PLUGIN);
define('STACKMAN_CFG_DIR', '/boot/config/plugins/'.STACKMAN_PLUGIN);
define('STACKMAN_CFG',     STACKMAN_CFG_DIR.'/'.STACKMAN_PLUGIN.'.cfg');

// Projection of the HEADER_MENU setting onto a file, written by
// scripts/apply_settings. The .page Cond expressions test for this instead of
// parsing the config, because Cond runs on every render of every page.
define('STACKMAN_MARKER_HEADER_MENU', STACKMAN_CFG_DIR.'/header_menu');

/**
 * Read the plugin config, falling back to the shipped defaults for any key the
 * user's config predates. Returns a flat key => string map.
 */
function stackman_cfg(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;

  $defaults = @parse_ini_file(STACKMAN_ROOT.'/default.cfg') ?: [];
  $user     = @parse_ini_file(STACKMAN_CFG) ?: [];
  return $cfg = array_merge($defaults, $user);
}

function stackman_cfg_bool(string $key): bool {
  return (stackman_cfg()[$key] ?? 'false') === 'true';
}

/**
 * Absolute path to the docker compose CLI plugin, or '' if it isn't installed.
 *
 * Unraid does not ship the compose CLI plugin — not even in 7.3 — so this is
 * expected to return '' on a fresh system until we install it.
 */
function stackman_compose_bin(): string {
  foreach ([
    '/usr/local/lib/docker/cli-plugins/docker-compose',
    '/usr/libexec/docker/cli-plugins/docker-compose',
    '/root/.docker/cli-plugins/docker-compose',
  ] as $path) {
    if (is_file($path) && is_executable($path)) return $path;
  }
  return '';
}

function stackman_compose_version(): string {
  if (!stackman_compose_bin()) return '';
  return trim((string)@shell_exec('docker compose version --short 2>/dev/null'));
}

function stackman_docker_running(): bool {
  $pidfile = '/var/run/dockerd.pid';
  if (!is_file($pidfile)) return false;
  return is_dir('/proc/'.trim((string)@file_get_contents($pidfile)));
}

/**
 * Containers on the system grouped by their compose project.
 *
 * This is the grouping key the whole stack presentation rests on: compose
 * stamps `com.docker.compose.project` on every container it creates, so stacks
 * group themselves with no folder configuration from the user. Containers with
 * no such label (i.e. created by Unraid templates or by hand) collect under ''.
 *
 * @return array<string, string[]> project name => container names
 */
function stackman_containers_by_project(): array {
  if (!stackman_docker_running()) return [];

  $fmt = '{{.Names}}\t{{index .Labels "com.docker.compose.project"}}';
  $out = (string)@shell_exec('docker ps -a --format '.escapeshellarg($fmt).' 2>/dev/null');

  $projects = [];
  foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    [$name, $project] = array_pad(explode("\t", $line, 2), 2, '');
    $projects[$project][] = $name;
  }
  ksort($projects);
  return $projects;
}
?>
