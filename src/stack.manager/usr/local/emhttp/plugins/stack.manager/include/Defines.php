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

// The one directory the volume picker will look inside. Everything a container
// should be given lives under here, and confining the picker to it means the
// only path the browser can ask about is one the user could already see in
// Unraid's own file browser. See stackman_browse_dirs().
define('STACKMAN_BROWSE_ROOT', '/mnt');

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
 * Run a command and return its output, with a hard time limit.
 *
 * Nothing here may hang. A web request that waits forever on `docker` is worse
 * than one that fails: the page sits there saying nothing, and the failure is
 * invisible. `timeout` kills the command and we carry on with what we have.
 *
 * @param string $cmd     already-quoted command line
 * @param int    $seconds how long to allow before killing it
 * @param int|null $code  set to the exit status; 124 means it timed out
 */
function stackman_sh(string $cmd, int $seconds = 10, ?int &$code = null): string {
  $lines = [];
  $code  = 1;

  // The command is handed to `sh -c` as a single argument rather than pasted
  // after `timeout`. Written the other way, `timeout 120 cd /somewhere && foo`
  // parses as "time-limit the cd" — and since cd is a shell builtin with no
  // program to run, it fails, the && short-circuits, and foo never executes
  // while the whole thing still reports success. That silently skipped the
  // "stop the containers" step of deleting a stack.
  //
  // stdin is closed explicitly too: a command that decides to prompt would
  // otherwise wait forever on a terminal that does not exist, holding a PHP
  // worker open with it.
  @exec(
    'timeout -k 2 '.(int)$seconds.' sh -c '.escapeshellarg($cmd).' </dev/null 2>/dev/null',
    $lines,
    $code
  );
  return implode("\n", $lines);
}

/**
 * Absolute path to the docker binary. PHP's environment is not a login shell,
 * so PATH cannot be relied on — resolve it once and call it explicitly.
 */
function stackman_docker_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'docker';
}

/**
 * Every location compose is known to install to.
 *
 * The first five are Docker's own CLI plugin search path, in the order Docker
 * itself uses. The last two are the older standalone `docker-compose` command,
 * which is a separate program rather than a Docker plugin.
 *
 * @return string[]
 */
function stackman_compose_paths(): array {
  return [
    '/root/.docker/cli-plugins/docker-compose',
    '/usr/local/lib/docker/cli-plugins/docker-compose',
    '/usr/local/libexec/docker/cli-plugins/docker-compose',
    '/usr/lib/docker/cli-plugins/docker-compose',
    '/usr/libexec/docker/cli-plugins/docker-compose',
    '/usr/local/bin/docker-compose',
    '/usr/bin/docker-compose',
  ];
}

/**
 * What compose is available, and in what form.
 *
 * Searching known directories is a guess — another plugin may have installed
 * compose somewhere this list does not cover, and the list will rot. So the
 * decision rests on running it: if `docker compose version` answers, compose
 * works, wherever it happens to live. The path is then looked up only so the
 * UI can say where it found it.
 *
 * Unraid does not ship compose itself, but the Compose Manager plugin does,
 * and a user may have installed it by hand. Any of those count.
 *
 * @return array{available:bool, version:string, path:string, form:string}
 *         form is 'plugin' (`docker compose`), 'standalone' (`docker-compose`)
 *         or '' when unavailable.
 */
function stackman_compose(): array {
  static $info = null;
  if ($info !== null) return $info;

  $docker = escapeshellarg(stackman_docker_bin());
  $info = ['available' => false, 'version' => '', 'path' => '', 'form' => ''];

  $version = trim(stackman_sh($docker.' compose version --short', 8));
  if ($version !== '') {
    $info['available'] = true;
    $info['version']   = $version;
    $info['form']      = 'plugin';
  } else {
    $version = trim(stackman_sh('docker-compose version --short', 8));
    if ($version !== '') {
      $info['available'] = true;
      $info['version']   = $version;
      $info['form']      = 'standalone';
    }
  }

  foreach (stackman_compose_paths() as $path) {
    if (is_file($path) && is_executable($path)) { $info['path'] = $path; break; }
  }
  if ($info['path'] === '') {
    $found = trim(stackman_sh('command -v docker-compose', 5));
    if ($found !== '' && is_file($found)) $info['path'] = $found;
  }

  return $info;
}

/** Absolute path to compose, or '' if it could not be located on disk. */
function stackman_compose_bin(): string {
  return stackman_compose()['path'];
}

function stackman_compose_version(): string {
  return stackman_compose()['version'];
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

  // `.Label "key"` looks up one label. The obvious-looking
  // `index .Labels "key"` does not work here: in `docker ps` templates .Labels
  // is a list, not a lookup table, and asking it for a named key fails the
  // whole command — which looked exactly like "no compose containers exist".
  $fmt = '{{.Names}}\t{{.Label "com.docker.compose.project"}}';
  $out = stackman_sh(
    escapeshellarg(stackman_docker_bin()).' ps -a --format '.escapeshellarg($fmt), 10
  );

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
