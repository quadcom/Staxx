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
 * Where Unraid keeps container application data, read from its own config so
 * the folder-creation button offers the same default Community Applications
 * does rather than inventing one of its own.
 *
 * That file belongs to Unraid, not this plugin, so every way of failing to
 * read it — missing, unreadable, parse_ini_file() returning false, a relative
 * or empty value — falls back to the path Unraid ships by default, quietly,
 * rather than raising an error.
 */
function stackman_appdata_root(): string {
  static $root = null;
  if ($root !== null) return $root;

  $fallback = '/mnt/user/appdata/';
  $cfg  = @parse_ini_file('/boot/config/docker.cfg') ?: [];
  $path = trim((string)($cfg['DOCKER_APP_CONFIG_PATH'] ?? ''));

  if ($path === '' || strpos($path, '/mnt/') !== 0) return $root = $fallback;
  return $root = rtrim($path, '/').'/';
}

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
 * Every network this server's docker knows about. The Form view's Network
 * mode dropdown offers these alongside the three built-ins (bridge/host/none),
 * which still work with an empty list — so a Docker that is down is not an
 * error here, just nothing extra to offer.
 *
 * A network compose made for a stack of its own is left out. Those come and
 * go with the stack that owns them, so offering "multi-tier_default" as
 * somewhere to attach a container is offering a name that may not exist
 * tomorrow. They are told apart by the label compose stamps on them, not by
 * their "_default" name, which anyone is free to use by hand.
 *
 * @return array<int, array{name:string, driver:string}>
 */
function stackman_docker_networks(): array {
  if (!stackman_docker_running()) return [];

  $fmt = '{{.Name}}|{{.Driver}}|{{.Labels}}';
  $out = stackman_sh(
    escapeshellarg(stackman_docker_bin()).' network ls --format '.escapeshellarg($fmt), 10
  );

  $networks = [];
  foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    [$name, $driver, $labels] = array_pad(explode('|', $line, 3), 3, '');
    $name = trim($name);
    if ($name === '' || strpos($labels, 'com.docker.compose.project') !== false) continue;
    $networks[] = ['name' => $name, 'driver' => trim($driver)];
  }
  return $networks;
}

/**
 * Every image:tag this server's docker has already pulled, offered while
 * typing an `image:` field so a common case does not need retyping.
 *
 * `<none>` tags mark dangling layers and half-finished builds — never
 * something anyone would type into an `image:` field — so they are filtered
 * out rather than shown as noise.
 *
 * @return string[] sorted, de-duplicated "repository:tag" strings
 */
function stackman_docker_images(): array {
  if (!stackman_docker_running()) return [];

  $out = stackman_sh(
    escapeshellarg(stackman_docker_bin()).' images --format '.escapeshellarg('{{.Repository}}:{{.Tag}}'), 10
  );

  $images = [];
  foreach (explode("\n", trim($out)) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '<none>') !== false) continue;
    $images[$line] = true; // de-duplicate as we go
  }
  $images = array_keys($images);
  sort($images);
  return $images;
}

/**
 * Tags Docker Hub has published for a repository, offered while typing an
 * `image:` field's tag half. This is the only thing in the plugin that talks
 * to a server other than this one, so the repo string is checked twice before
 * it gets anywhere near a URL: first for shape, then quoted on top of that —
 * belt and braces, not either on its own.
 *
 * A bare single-segment name (`postgres`) is Docker's own shorthand for
 * `library/postgres`, where Hub keeps official images. `lscr.io/linuxserver/x`
 * and `ghcr.io/linuxserver/x` are mirrors of the same image Hub already
 * indexes under `linuxserver/x`, and worth the two lines because linuxserver
 * is what most Unraid users run. Any other host-qualified name — a private
 * registry, a self-hosted one, anything else — is refused outright: this
 * plugin does not guess at credentials or API shapes it has never seen, and
 * declining quietly is correct, because the field falls back to a plain text
 * box the moment the suggestion list comes back empty.
 *
 * Every failure — no network, DNS, a non-JSON body, an HTTP error, a reply
 * shaped nothing like tags — returns the same empty array rather than an
 * exception or a message. This call runs while someone is mid-keystroke;
 * surfacing a registry error in the middle of typing would be noise for a
 * field that still works perfectly well as free text.
 *
 * @return string[] up to 50 tag names, most recently updated first
 */
function stackman_image_tags(string $repo): array {
  $repo = trim($repo);
  if ($repo === '') return [];

  $parts = explode('/', $repo);
  if (count($parts) === 3 && $parts[1] === 'linuxserver' && in_array($parts[0], ['lscr.io', 'ghcr.io'], true)) {
    $repo = $parts[1].'/'.$parts[2];
  } elseif (strpos($parts[0], '.') !== false || strpos($parts[0], ':') !== false) {
    // Host-qualified and not one of the two mirrors above — decline rather
    // than guess at a registry we know nothing about.
    return [];
  } elseif (strpos($repo, '/') === false) {
    $repo = 'library/'.$repo;
  }

  // Shape: [namespace/]name, lowercase alphanumerics with . _ - separators,
  // at most one slash. The 'D' modifier keeps $ from also matching just
  // before a trailing newline, which is PCRE's default and would otherwise
  // let a name end in "\n<anything>" slip past this check.
  if (!preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)?$#D', $repo)) {
    return [];
  }

  $url = 'https://hub.docker.com/v2/repositories/'.$repo.'/tags?page_size=50&ordering=last_updated';
  // A timeout a little above curl's own --max-time, so curl reports the
  // failure itself rather than being killed mid-flight by stackman_sh().
  $out = stackman_sh('curl -fsSL --max-time 8 '.escapeshellarg($url), 12);

  $data = json_decode($out, true);
  if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) return [];

  $tags = [];
  foreach ($data['results'] as $result) {
    if (!is_array($result) || !isset($result['name']) || !is_string($result['name'])) continue;
    $tags[] = $result['name'];
    if (count($tags) >= 50) break;
  }
  return $tags;
}

/**
 * Docker Hub's own search, offered in the Apps dialog as a second source
 * alongside the Community Applications catalogue — CA is a curated list, not
 * an index of everything on Hub, so a query that misses there can still land
 * here. Takes the text typed into the search box.
 *
 * Same shape check, same `curl` through stackman_sh() and the same "return
 * an empty array for every failure" contract as stackman_image_tags(), and
 * for the same reason: this runs on every keystroke, live against Hub, with
 * no catalogue behind it to fall back on. A dropped connection, a non-JSON
 * body, an HTTP error — all of it comes back as no results rather than an
 * error, because a search that quietly finds nothing is better than one that
 * interrupts typing to complain.
 *
 * @return array<int, array{name:string, desc:string, stars:int, pulls:int, official:bool}> up to 25 hits
 */
function stackman_hub_search(string $q): array {
  // Lower-cased before the shape check, not after: a repository name on Hub
  // is always lower case, so "Jellyfin" is the same search as "jellyfin" —
  // but the check below rejects a capital letter, which would have made a
  // capitalised query silently return nothing while the catalogue beside it
  // went on matching perfectly.
  $q = strtolower(trim($q));
  if (strlen($q) < 3) return [];

  // Same shape a repository name is held to: lowercase alphanumerics with
  // . _ - separators, at most one slash. A search box invites more than
  // that, but anything wider is not a repository fragment worth sending to
  // this particular endpoint. The 'D' modifier keeps $ from also matching
  // just before a trailing newline, PCRE's default.
  if (!preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)?$#D', $q)) {
    return [];
  }

  $url = 'https://hub.docker.com/v2/search/repositories/?query='.rawurlencode($q).'&page_size=25';
  // A timeout a little above curl's own --max-time, so curl reports the
  // failure itself rather than being killed mid-flight by stackman_sh().
  $out = stackman_sh('curl -fsSL --max-time 8 '.escapeshellarg($url), 12);

  $data = json_decode($out, true);
  if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) return [];

  $hits = [];
  foreach ($data['results'] as $result) {
    if (!is_array($result) || !isset($result['repo_name']) || !is_string($result['repo_name'])) continue;
    $hits[] = [
      'name'     => $result['repo_name'],
      'desc'     => trim((string)($result['short_description'] ?? '')),
      'stars'    => (int)($result['star_count'] ?? 0),
      'pulls'    => (int)($result['pull_count'] ?? 0),
      'official' => (bool)($result['is_official'] ?? false),
    ];
    if (count($hits) >= 25) break;
  }
  return $hits;
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
