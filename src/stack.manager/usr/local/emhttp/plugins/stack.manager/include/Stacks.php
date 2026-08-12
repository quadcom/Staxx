<?PHP
/* Stack Manager — the stack model.
 * Copyright 2026, Stack Manager contributors.
 *
 * Everything that reads or writes a stack goes through this file. A "stack" is
 * a directory containing a compose file, and nothing else is required: no
 * database, no index, no metadata file. Drop a compose file in a folder and it
 * is a stack. Delete the folder and it is gone.
 *
 * That is a deliberate design choice. The compose file is the source of truth,
 * so anything we would keep alongside it is a second copy that can disagree
 * with it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/stack.manager/include/Defines.php';

if (defined('STACKMAN_JOB_DIR')) return;

// Job logs live in RAM, not on the flash drive. They are noisy, they are
// worthless after a reboot, and the flash drive has a finite number of writes.
define('STACKMAN_JOB_DIR', '/tmp/stack.manager/jobs');

// Written to a job's log once the command finishes, followed by its exit code.
// Deliberately unlikely to appear in compose output.
define('STACKMAN_JOB_END', '###stackman-finished###');

// Compose reads whichever of these it finds first, in this order.
const STACKMAN_COMPOSE_FILENAMES = [
  'compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml',
];

/* ------------------------------------------------------------------ paths -- */

function stackman_stack_root(): string {
  $root = trim((string)(stackman_cfg()['STACK_ROOT'] ?? ''));
  if ($root === '') $root = STACKMAN_CFG_DIR.'/stacks';
  return rtrim($root, '/');
}

/**
 * Is this a name we are willing to turn into a directory?
 *
 * Everything the user types here ends up in a filesystem path, so this is the
 * gate that stops a crafted name reaching outside the stack folder. Reject
 * anything that is not plainly a name: no slashes, no dots leading a segment,
 * no traversal.
 */
function stackman_valid_name(string $name): bool {
  if ($name === '' || strlen($name) > 63) return false;
  if (strpos($name, '..') !== false) return false;
  return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name);
}

/**
 * Is this a stack's path — either "name" or "folder/name"?
 *
 * A stack now lives either at the top of the stack root or one folder down, so
 * its identity carries at most one slash. This is the same gate as
 * stackman_valid_name() and NOT a looser version of it: the string is split on
 * "/" and every segment is handed to that function unchanged. Widening the
 * regex to allow a slash would be the obvious way to write this and would also
 * be the bug — one permissive character class is all it takes to turn
 * stackman_stack_dir() into a way out of the stack root.
 *
 * Two segments maximum, deliberately. Folders are one level deep; see the note
 * on stackman_scan_stacks().
 */
function stackman_valid_path(string $rel): bool {
  if ($rel === '' || strlen($rel) > 127) return false;

  $parts = explode('/', $rel);
  if (count($parts) > 2) return false;

  foreach ($parts as $part) {
    if (!stackman_valid_name($part)) return false;
  }
  return true;
}

/** The folder half of a stack's path, or '' when it sits at the top. */
function stackman_path_folder(string $rel): string {
  $at = strpos($rel, '/');
  return $at === false ? '' : substr($rel, 0, $at);
}

/** The stack's own directory name, without its folder. */
function stackman_path_leaf(string $rel): string {
  $at = strrpos($rel, '/');
  return $at === false ? $rel : substr($rel, $at + 1);
}

function stackman_stack_dir(string $rel): string {
  return stackman_stack_root().'/'.$rel;
}

/**
 * List the folders inside one directory, for the volume picker.
 *
 * Rooted at /mnt and nowhere else. This one is worth reading carefully, because
 * it is the only place in the plugin that takes an arbitrary path from the
 * browser rather than a validated name.
 *
 * The check happens AFTER realpath(), never before. Testing the string as typed
 * would let "/mnt/../etc" through, since it starts with the root; testing a
 * symlink by its own path would let a link inside /mnt pointing at / through as
 * well. Resolving first and comparing the result is what closes both, and it is
 * why nothing here is built out of string prefixes on the raw input.
 *
 * Read-only by construction: it opens a directory and lists names. There is no
 * create, no rename, no delete, so the worst a crafted path can do is list a
 * folder the user could already see in Unraid's own file browser.
 */
function stackman_browse_dirs(string $path): array {
  $root = STACKMAN_BROWSE_ROOT;
  $want = trim($path) === '' ? $root : trim($path);

  if (strlen($want) > 1024) {
    return ['error' => 'That path is too long to look at.'];
  }

  $real = @realpath($want);
  if ($real === false || !is_dir($real)) {
    return ['error' => 'There is no folder called "'.$want.'" on this server.'];
  }
  if ($real !== $root && strpos($real, $root.'/') !== 0) {
    return ['error' => 'Only folders under '.$root.' can be browsed. '
                     . 'Type a path into the box if you need one elsewhere.'];
  }

  $dh = @opendir($real);
  if ($dh === false) {
    return ['error' => 'The folder "'.$real.'" cannot be read.'];
  }

  // A share can hold tens of thousands of files, and each name costs a stat to
  // tell a folder from a file. Both counts are capped so that pointing this at
  // a media library answers quickly with a partial list instead of slowly with
  // a complete one nobody would scroll through anyway.
  $dirs = [];
  $seen = 0;
  $more = false;

  while (($entry = readdir($dh)) !== false) {
    if (++$seen > 20000)              { $more = true; break; }
    if ($entry === '.' || $entry === '..') continue;
    if ($entry[0] === '.')            continue;      // .Recycle.Bin and friends
    if (!is_dir($real.'/'.$entry))    continue;
    $dirs[] = $entry;
    if (count($dirs) >= 500)          { $more = true; break; }
  }
  closedir($dh);

  natcasesort($dirs);

  return [
    'path' => $real,
    'up'   => $real === $root ? '' : dirname($real),
    'dirs' => array_values($dirs),
    'more' => $more,
  ];
}

/**
 * Make one folder inside a folder the picker is already showing.
 *
 * This exists because of what happens without it. Docker creates a missing
 * bind-mount source itself when a stack first starts, owned root:root and mode
 * 755 — and most containers run as 99:100, which then cannot write to it. The
 * failure lands at first start, says "permission denied", and cannot be undone
 * over a network share because the folder belongs to root.
 *
 * So the new folder is made to look like the one it goes into: owner, group
 * and mode are copied from the parent. Made inside appdata, it comes out
 * nobody:users 0777 like everything beside it.
 *
 * The name goes through stackman_valid_name(), which forbids a slash — so this
 * can only ever create one folder, directly inside a parent that has already
 * been resolved and checked against the browse root by the same rule browsing
 * uses. Nothing here takes a path apart or puts one together from user text.
 */
function stackman_browse_mkdir(string $parent, string $name, string &$error): string {
  $name = trim($name);
  if (!stackman_valid_name($name)) {
    $error = 'Use letters, numbers, dots, dashes and underscores for a folder name, '
           . 'starting with a letter or a number.';
    return '';
  }

  $root = STACKMAN_BROWSE_ROOT;
  $real = @realpath(trim($parent) === '' ? $root : trim($parent));

  if ($real === false || !is_dir($real)) {
    $error = 'There is no folder called "'.$parent.'" to make this one inside.';
    return '';
  }
  if ($real !== $root && strpos($real, $root.'/') !== 0) {
    $error = 'Folders can only be made under '.$root.'.';
    return '';
  }

  $path = $real.'/'.$name;
  if (file_exists($path)) {
    $error = is_dir($path)
      ? 'There is already a folder called "'.$name.'" in here.'
      : 'There is already a file called "'.$name.'" in here.';
    return '';
  }

  $st   = @stat($real);
  $mode = $st ? ($st['mode'] & 0777) : 0777;

  if (!@mkdir($path, $mode)) {
    $error = 'The folder "'.$path.'" could not be created. Check that '.$real
           . ' can be written to.';
    return '';
  }

  // Ownership first, then the mode: chown can clear permission bits, so setting
  // them the other way round would sometimes undo itself. The mode is set again
  // regardless of what mkdir() was given, because the process umask filters it.
  if ($st) { @chown($path, $st['uid']); @chgrp($path, $st['gid']); }
  @chmod($path, $mode);

  return $path;
}

/* -------------------------------------------------------------- timezones -- */

/**
 * Every timezone PHP knows, with the two offsets that matter.
 *
 * Nothing is hardcoded and no data file is shipped. PHP carries its own copy of
 * the IANA database — a fuller one than /usr/share/zoneinfo, which Unraid
 * trims — and it is already the authority for anything else on this box that
 * needs a zone name.
 *
 * The important number is `std`, the STANDARD offset. Placing a zone by what
 * its clock says today would move half the world sideways twice a year, and
 * Toronto and Bogota would swap columns every spring. Standard time is what a
 * map of the world's time zones shows, so that is what the map is drawn from.
 * `now` rides along beside it so the list can still say what the clock reads at
 * this moment.
 *
 * Finding the standard offset: walk forward through the zone's transitions and
 * take the first one that is not daylight saving. A zone that never uses
 * daylight saving matches on its first transition, so there is no special case.
 */
function stackman_timezones(): array {
  $now  = time();
  $ahead = $now + 2 * 365 * 86400;      // long enough to contain a winter
  $out  = [];

  foreach (DateTimeZone::listIdentifiers() as $name) {
    $tz  = new DateTimeZone($name);
    $cur = (new DateTime('now', $tz))->getOffset();

    $std = null;
    foreach ($tz->getTransitions($now, $ahead) as $tr) {
      if (empty($tr['isdst'])) { $std = $tr; break; }
    }

    $at = strrpos($name, '/');
    $out[] = [
      'name'   => $name,
      // "America/Argentina/Buenos_Aires" reads as "Buenos Aires" in the list,
      // with the full name shown beside it so nothing is guessed at.
      'city'   => str_replace('_', ' ', $at === false ? $name : substr($name, $at + 1)),
      'std'    => (int)round(($std ? $std['offset'] : $cur) / 60),
      'now'    => (int)round($cur / 60),
      'abbr'   => (string)($std['abbr'] ?? ''),
    ];
  }

  return ['zones' => $out];
}

/** Absolute path to the compose file inside a stack directory, or ''. */
function stackman_find_compose_file(string $dir): string {
  foreach (STACKMAN_COMPOSE_FILENAMES as $f) {
    if (is_file($dir.'/'.$f)) return $dir.'/'.$f;
  }
  return '';
}

/**
 * Walk the stack root and say what everything in it is.
 *
 * THE ONE RULE: a directory at the top of the root holding a compose file is a
 * STACK; one that does not is a FOLDER. Nothing is recorded anywhere — the
 * shape of the tree is the whole answer, which is what keeps the promise that a
 * stack is a directory holding a compose file and nothing else. Drop a folder
 * of stacks onto another server and the layout arrives with it.
 *
 * One level, and only one. A directory inside a folder is always read as a
 * stack, so a folder inside a folder shows up as a stack with no compose file
 * rather than silently disappearing — which is both a readable error and the
 * reason folder-create refuses to nest.
 *
 * Anything whose name this plugin could not act on is skipped rather than shown.
 * A row we cannot start, stop or delete is worse than no row.
 *
 * @return array{stacks: array<int, array{rel:string, dir:string, folder:string, leaf:string}>,
 *               folders: string[]}
 */
function stackman_scan_stacks(): array {
  $root = stackman_stack_root();
  $out  = ['stacks' => [], 'folders' => []];
  if (!is_dir($root)) return $out;

  foreach ((array)@scandir($root) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (!stackman_valid_name($entry)) continue;

    $dir = $root.'/'.$entry;
    if (!is_dir($dir)) continue;

    if (stackman_find_compose_file($dir) !== '') {
      $out['stacks'][] = ['rel' => $entry, 'dir' => $dir, 'folder' => '', 'leaf' => $entry];
      continue;
    }

    $out['folders'][] = $entry;

    foreach ((array)@scandir($dir) as $kid) {
      if ($kid === '.' || $kid === '..') continue;
      if (!stackman_valid_name($kid)) continue;

      $kidDir = $dir.'/'.$kid;
      if (!is_dir($kidDir)) continue;

      $out['stacks'][] = [
        'rel' => $entry.'/'.$kid, 'dir' => $kidDir, 'folder' => $entry, 'leaf' => $kid
      ];
    }
  }

  natcasesort($out['folders']);
  $out['folders'] = array_values($out['folders']);
  return $out;
}

/** Just the folder names, for the "move to folder" menu and the layout. */
function stackman_folder_names(): array {
  return stackman_scan_stacks()['folders'];
}

/* --------------------------------------------------------------- compose -- */

/**
 * The compose command to run, already shell-quoted, or '' if unavailable.
 * Either `/usr/bin/docker compose` or the standalone `docker-compose`.
 */
function stackman_compose_cmd(): string {
  $info = stackman_compose();
  if (!$info['available']) return '';
  if ($info['form'] === 'standalone') {
    return $info['path'] !== '' ? escapeshellarg($info['path']) : 'docker-compose';
  }
  return escapeshellarg(stackman_docker_bin()).' compose';
}

/**
 * What compose itself thinks is running, keyed by compose file path.
 *
 * Asking compose is better than inspecting containers ourselves: it already
 * knows which config file produced which project, and it is the same answer
 * the command line would give.
 *
 * @return array<string, array{name:string, status:string}>
 */
function stackman_compose_ls(): array {
  return stackman_compose_state()['byFile'];
}

/**
 * The same answer indexed three ways, so that moving a stack does not lose it.
 *
 * The full file path is the best key and stays the first choice: it ties a
 * running project to THIS directory and to no other. But the path compose
 * reports is the one recorded when the containers were created, so filing a
 * stack into a folder leaves it pointing at where the file used to be, and a
 * lookup by path alone would call a running stack stopped until it was
 * recreated.
 *
 * So there are two fallbacks, in this order:
 *
 *   byTail  the stack's own directory name plus the compose filename —
 *           "05-busybox-http/compose.yaml". Filing a stack changes what is
 *           ABOVE that, never the tail itself, so this survives the move while
 *           still being specific enough to mean one stack.
 *
 *   byName  the compose project name. Last, because a compose file may set its
 *           own `name:`, in which case the project is not named after any
 *           directory at all — which is exactly the case that proved a
 *           directory-name fallback was not enough on its own.
 *
 * All of it corrects itself the next time the stack is started, because that
 * restamps the path.
 *
 * @return array{byFile:array<string,array{name:string,status:string}>,
 *               byTail:array<string,array{name:string,status:string}>,
 *               byName:array<string,array{name:string,status:string}>}
 */
function stackman_compose_state(): array {
  static $state = null;
  if ($state !== null) return $state;
  $state = ['byFile' => [], 'byTail' => [], 'byName' => []];

  $cmd = stackman_compose_cmd();
  if ($cmd === '' || !stackman_docker_running()) return $state;

  $json = stackman_sh($cmd.' ls --all --format json', 15);
  $rows = json_decode($json, true);
  if (!is_array($rows)) return $state;

  foreach ($rows as $row) {
    if (!is_array($row)) continue;

    $entry = [
      'name'   => (string)($row['Name'] ?? ''),
      'status' => (string)($row['Status'] ?? ''),
    ];
    if ($entry['name'] !== '') $state['byName'][strtolower($entry['name'])] = $entry;

    foreach (explode(',', (string)($row['ConfigFiles'] ?? '')) as $file) {
      $file = trim($file);
      if ($file === '') continue;
      $state['byFile'][$file] = $entry;
      $state['byTail'][stackman_file_tail($file)] = $entry;
    }
  }
  return $state;
}

/** "…/stacks/Media/jellyfin/compose.yaml" -> "jellyfin/compose.yaml". */
function stackman_file_tail(string $file): string {
  return basename(dirname($file)).'/'.basename($file);
}

/**
 * What compose knows about one stack: by its file, then by its tail, then by
 * its project name. See stackman_compose_state() for why there are three.
 *
 * @param string $file absolute path to the compose file, or ''
 * @param string $leaf the stack's own directory name, without its folder
 */
function stackman_state_for(string $file, string $leaf): ?array {
  $state = stackman_compose_state();
  if ($file === '') return $state['byName'][strtolower($leaf)] ?? null;

  if (isset($state['byFile'][$file])) return $state['byFile'][$file];

  $tail = stackman_file_tail($file);
  if (isset($state['byTail'][$tail])) return $state['byTail'][$tail];

  return $state['byName'][strtolower($leaf)] ?? null;
}

/**
 * Read a block-mapping YAML document into a flat "path => value" map.
 *
 * NOT a YAML parser, and it must never be pointed at a file a user wrote. It is
 * fed the output of `docker compose config`, which is CANONICAL yaml: two-space
 * indentation throughout, no anchors, no aliases, no flow style for mappings,
 * every shorthand and override already resolved. Compose guarantees that shape,
 * which is what makes reading it by hand reasonable — the repository's own
 * 07-yaml-quirks stack exists precisely to break anything that tries this on a
 * hand-written file. (PHP on Unraid has no YAML extension, and compose's JSON
 * output cannot be used: it silently drops service-level `x-` fields, which are
 * the whole reason for reading the file.)
 *
 * Anything it does not understand — sequences, block scalars, tags — is SKIPPED
 * rather than guessed at. A missing key reads as "not set", which is a correct
 * answer; a guessed one is not.
 *
 * Paths are joined with a null byte, which cannot appear in a YAML key.
 *
 * @return array<string,string>
 */
function stackman_yaml_flatten(string $yaml): array {
  $out   = [];
  $path  = [];            // list of [indent, key] currently open
  $skip  = null;          // indent to skip past, or null
  $lines = explode("\n", $yaml);

  foreach ($lines as $raw) {
    $line = rtrim($raw, "\r");
    if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

    $indent = strlen($line) - strlen(ltrim($line, ' '));

    // Inside something we chose not to read — a sequence or a multi-line
    // string. It ends at the first line indented no further than the key.
    if ($skip !== null) {
      if ($indent > $skip) continue;
      $skip = null;
    }

    $body = ltrim($line, ' ');

    // A sequence item. Everything at or below it belongs to the sequence.
    if (strncmp($body, '- ', 2) === 0 || $body === '-') { $skip = max(0, $indent - 1); continue; }

    if (!preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^:]+):(?:\s+(.*))?$/', $body, $m)) {
      continue;
    }

    $key = trim($m[1]);
    if (strlen($key) > 1 && ($key[0] === '"' || $key[0] === "'")) {
      $key = substr($key, 1, -1);
    }
    $value = isset($m[2]) ? trim($m[2]) : '';

    // Close every level this line has stepped back out of.
    while ($path && end($path)[0] >= $indent) array_pop($path);
    $path[] = [$indent, $key];

    if ($value === '') continue;                          // a nested mapping

    // A multi-line string. Its content is not needed by anything here, and
    // reading it wrong would swallow the keys that follow it.
    if (preg_match('/^[|>][+-]?\d*$/', $value)) { $skip = $indent; continue; }

    if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")) {
      $value = substr($value, 1, -1);
    }

    $out[implode("\0", array_column($path, 1))] = $value;
    array_pop($path);                                     // a scalar opens nothing
  }

  return $out;
}

/**
 * Everything this plugin needs to know from a compose file.
 *
 * One `docker compose config` per stack, which costs the same 60ms the old
 * `config --services` did while answering three questions instead of one: which
 * services exist, what image each one runs, and what `x-unraid` says about them.
 *
 * The image matters for more than display. A service that has never been
 * started has no container, so `docker ps` knows nothing about it — reading it
 * from the compose file is the only way such a row can have an icon before it
 * has ever run.
 *
 * @return array{ok:bool, error:?string, x:array<string,string>,
 *                services:array<string,array{image:string, container_name:string,
 *                                            x:array<string,string>}>}
 */
function stackman_compose_meta(string $file, ?string &$error = null): array {
  static $cache = [];
  if (isset($cache[$file])) { $error = $cache[$file]['error']; return $cache[$file]; }

  $meta = ['ok' => false, 'error' => null, 'x' => [], 'services' => []];

  $cmd  = stackman_compose_cmd();
  $yaml = '';

  if ($cmd !== '') {
    $code = 1;
    $out  = stackman_sh($cmd.' -f '.escapeshellarg($file).' config 2>&1', 15, $code);
    if ($code !== 0) {
      // Compose is installed and it rejected the file. Report that rather than
      // falling through to the rough read below — guessing at a broken file
      // produces a list of things that are not services, which reads as though
      // the file were fine.
      $meta['error'] = trim($out) !== '' ? trim($out) : 'Compose could not read this file.';
      $error = $meta['error'];
      return $cache[$file] = $meta;
    }
    $yaml = $out;
    $meta['ok'] = true;
  } else {
    // Compose is not installed. Read the file directly so the list still says
    // something useful, and accept that a file using anchors or flow style will
    // be read poorly — there is nothing better available on such a system.
    $yaml = (string)@file_get_contents($file);
    $meta['ok'] = $yaml !== '';
  }

  foreach (stackman_yaml_flatten($yaml) as $path => $value) {
    $parts = explode("\0", $path);

    if ($parts[0] === 'x-unraid' && count($parts) === 2) {
      $meta['x'][$parts[1]] = $value;
      continue;
    }

    if ($parts[0] !== 'services' || count($parts) < 3) continue;
    $service = $parts[1];
    if (!isset($meta['services'][$service])) {
      $meta['services'][$service] = ['image' => '', 'container_name' => '', 'x' => []];
    }

    if ($parts[2] === 'image' && count($parts) === 3) {
      $meta['services'][$service]['image'] = $value;
    } elseif ($parts[2] === 'container_name' && count($parts) === 3) {
      // What a one-service stack is named after. Only present when the file
      // sets it — compose does not invent one here, it makes the runtime name
      // up at start time instead.
      $meta['services'][$service]['container_name'] = $value;
    } elseif ($parts[2] === 'x-unraid' && count($parts) === 4) {
      $meta['services'][$service]['x'][$parts[3]] = $value;
    }
  }

  $error = null;
  return $cache[$file] = $meta;
}

/**
 * Service names declared in a compose file.
 *
 * Kept as its own function because most callers want nothing else; the reading
 * itself happens once, in stackman_compose_meta(), and is cached there.
 *
 * @return string[]
 */
function stackman_service_names(string $file, ?string &$error = null): array {
  return array_keys(stackman_compose_meta($file, $error)['services']);
}

/**
 * What to call a stack on screen.
 *
 * A folder holding several containers needs a title, and its own name is that
 * title. A folder holding ONE container does not: the folder is an artefact of
 * how stacks are stored, and naming the row after it says the same word twice.
 * Such a row is named after the container instead.
 *
 *   x-unraid: name:   whoever wrote the file said what to call it. Obeyed
 *                     whatever the service count, because it is the only
 *                     statement of intent available.
 *   container_name:   one service only. Compose's own name for the container.
 *   the service key   one service with no container_name:.
 *   the folder name   everything else.
 *
 * Two things deliberately do NOT feed this. A service's own x-unraid `name` is
 * for the form renderer and titles a field group, not the row. And docker's
 * live container name is never used: compose invents `folder-service-1` when
 * the file does not set container_name:, which is worse than the service key,
 * and a name that only exists while the stack is up would change every time it
 * started and stopped. This answer comes from the file alone, so it is the same
 * running or not.
 *
 * @param string $dirName the stack's folder name
 * @param array  $meta    from stackman_compose_meta()
 */
function stackman_stack_title(string $dirName, array $meta): string {
  $own = trim((string)($meta['x']['name'] ?? ''));
  if ($own !== '') return $own;

  if (count($meta['services']) === 1) {
    $service = array_key_first($meta['services']);
    $cname   = trim((string)($meta['services'][$service]['container_name'] ?? ''));
    // Cast because a service called `1234` is an int key by the time PHP has
    // built the array, and this function promises a string.
    return $cname !== '' ? $cname : (string)$service;
  }

  return $dirName;
}

/* ----------------------------------------------------------------- stacks -- */

/**
 * Every stack on disk, with whatever compose knows about its state.
 *
 * `name` is the folder on disk and is the stack's identity — every command, the
 * folders file and every selector on the page are keyed on it. `title` is what
 * the row is labelled with and is for display only; the two are often the same.
 *
 * @return array<int, array{name:string, title:string, dir:string, file:string,
 *                          filename:string, services:string[], x:array<string,string>,
 *                          project:string, status:string, running:bool, hasFile:bool,
 *                          parses:bool, error:?string}>
 */
function stackman_list_stacks(): array {
  $stacks = [];

  foreach (stackman_scan_stacks()['stacks'] as $found) {
    $dir    = $found['dir'];
    $file   = stackman_find_compose_file($dir);
    $state  = stackman_state_for($file, $found['leaf']);
    $status = $state['status'] ?? '';

    $parseError = null;
    $meta       = $file !== ''
      ? stackman_compose_meta($file, $parseError)
      : ['ok' => false, 'error' => null, 'x' => [], 'services' => []];
    $services   = array_keys($meta['services']);

    $stacks[] = [
      // The stack's identity is its path under the root — "jellyfin" at the
      // top, or "Media/jellyfin" one folder down. Every command, every DOM
      // attribute and every selector on the page is keyed on it.
      'name'     => $found['rel'],
      'folder'   => $found['folder'],
      'leaf'     => $found['leaf'],
      'title'    => stackman_stack_title($found['leaf'], $meta),
      'dir'      => $dir,
      'file'     => $file,
      'filename' => $file !== '' ? basename($file) : '',
      'services' => $services,
      // The stack's own x-unraid block: display name, icon, category and so on.
      'x'        => $meta['x'],
      'project'  => $state['name'] ?? '',
      'status'   => $status,
      // compose reports things like "running(3)" or "exited(1)".
      'running'  => stripos($status, 'running') !== false,
      // Two separate questions. A file that exists but does not parse still
      // needs an Edit button — that is the only way to fix it — but must not
      // offer Start.
      'hasFile'  => $file !== '',
      'parses'   => $file !== '' && $parseError === null,
      'error'    => $parseError,
    ];
  }

  // Sorted by what the page shows, not by what is on disk, or the list reads as
  // though it were in no order at all. The folder name breaks a tie so two
  // stacks sharing a title keep a stable order between refreshes.
  usort($stacks, function ($a, $b) {
    $by = strnatcasecmp($a['title'], $b['title']);
    return $by !== 0 ? $by : strnatcasecmp($a['name'], $b['name']);
  });
  return $stacks;
}

/**
 * Every compose-managed container on the machine, indexed two ways.
 *
 * This is what fills the rows underneath a stack. One `docker ps -a` covers the
 * whole machine and costs about 16 milliseconds even with eighty containers on
 * it, which is cheap enough to refresh on every state check rather than cache
 * and risk showing a container that has since been recreated.
 *
 * `-a` on purpose. A stopped container is still part of its stack and still
 * needs a row — hiding it would make a half-failed stack look like a smaller
 * one that is working. Containers that have never been created do not appear
 * here at all; those rows come from the compose file's service list instead.
 *
 * INDEXED BY COMPOSE FILE, not just by project name. Compose stamps every
 * container it creates with the file it came from, and that is the same key
 * this plugin already identifies a stack by. Matching on the project name
 * instead would need the name, which is only knowable from `compose ls` while
 * the project still exists — and would go wrong for any compose file that sets
 * its own `name:`, which is exactly when the folder name is not the project.
 *
 * @return array{byFile: array<string,array>, byProject: array<string,array>}
 */
function stackman_container_index(): array {
  static $index = null;
  if ($index !== null) return $index;

  $index = ['byFile' => [], 'byProject' => []];
  if (!stackman_docker_running()) return $index;

  // `.Label "key"` looks up one label. `index .Labels "key"` does not work in a
  // docker ps template — see the note in stackman_containers_by_project().
  //
  // The trailing "end" holds the last field open: PHP's exec() trims trailing
  // whitespace from each line, so a container whose final label is empty would
  // lose the tab before it and arrive one field short. See the longer note in
  // stackman_container_net().
  //
  // Unlike `docker inspect`, `docker ps --format` DOES translate \t into a tab,
  // which is why this one can be written the readable way.
  $fmt = '{{.ID}}\t{{.Names}}\t{{.State}}\t{{.Status}}\t{{.Image}}\t'
       . '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.service"}}\t'
       . '{{.Label "com.docker.compose.project.config_files"}}\tend';

  $out = stackman_sh(
    escapeshellarg(stackman_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
  );

  foreach (explode("\n", $out) as $line) {
    if (trim($line) === '') continue;
    $c = explode("\t", $line);
    if (count($c) < 7) continue;
    if ($c[5] === '') continue;                   // not compose-managed

    $row = [
      'id'      => $c[0],
      'name'    => $c[1],
      'state'   => $c[2],
      'status'  => $c[3],
      'image'   => $c[4],
      'project' => $c[5],
      'service' => $c[6],
    ];

    $index['byProject'][$c[5]][] = $row;

    // A project can be built from several files ("a.yml,b.yml"); it is listed
    // under each, the same way stackman_compose_ls() does it.
    foreach (explode(',', $c[7] ?? '') as $file) {
      $file = trim($file);
      if ($file !== '') $index['byFile'][$file][] = $row;
    }
  }

  $sort = fn(&$lists) => array_walk($lists, function (&$list) {
    usort($list, fn($a, $b) => strnatcasecmp($a['service'].'/'.$a['name'],
                                             $b['service'].'/'.$b['name']));
  });
  $sort($index['byFile']);
  $sort($index['byProject']);

  return $index;
}

/**
 * This server's own address, for turning a published port into somewhere you
 * could actually type.
 *
 * A port bound to 0.0.0.0 is listening on every address the machine has, which
 * is true but useless to read. Unraid records the real one, so that is shown
 * instead. Empty if it cannot be determined, in which case ports are shown
 * without an address rather than with a wrong one.
 */
function stackman_host_ip(): string {
  static $ip = null;
  if ($ip !== null) return $ip;

  $ini = @parse_ini_file('/var/local/emhttp/network.ini') ?: [];
  // The interface index is part of the key: IPADDR:0 is the first address.
  foreach (['IPADDR:0', 'IPADDR', 'IPADDR:1'] as $key) {
    $value = trim((string)($ini[$key] ?? ''));
    if ($value !== '') return $ip = $value;
  }
  return $ip = '';
}

/**
 * What kind of network each docker network is, by name.
 *
 * The distinction that matters is bridge or not. A bridge keeps its containers
 * on a private address the rest of the LAN cannot reach, so the useful thing to
 * name is the network itself. Anything else — Unraid's br0.x macvlan networks,
 * or host networking — puts the container somewhere you can actually type, so
 * the address is the useful thing.
 *
 * One call, about ten milliseconds, and the answer cannot be inferred from the
 * container: the driver belongs to the network, not to the thing attached to it.
 *
 * @return array<string,string> network name => driver
 */
function stackman_network_drivers(): array {
  static $drivers = null;
  if ($drivers !== null) return $drivers;

  $drivers = [];
  if (!stackman_docker_running()) return $drivers;

  // `docker network ls` shares the formatter used by `docker ps`, which does
  // translate \t. The trailing "end" guards against exec() trimming a line
  // whose last field is empty — see stackman_container_net().
  $out = stackman_sh(
    escapeshellarg(stackman_docker_bin()).' network ls --format '
    .escapeshellarg('{{.Name}}\t{{.Driver}}\tend'), 15
  );

  foreach (explode("\n", $out) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 2 || $c[0] === '') continue;
    $drivers[$c[0]] = $c[1];
  }

  return $drivers;
}

/**
 * Where each container can be reached, keyed by container id.
 *
 * Two quite different situations, and the difference is the whole point:
 *
 *   published ports   The container sits on a docker bridge with a private
 *                     address nobody outside can use, and a port on the SERVER
 *                     forwards to it. What you type is the server's address.
 *
 *   its own address   Unraid's br0.x networks give a container a real address
 *                     on your LAN. Nothing is forwarded and nothing is
 *                     published — you type the container's own address and the
 *                     port the application listens on.
 *
 * Reporting only the first would leave every br0.x container looking like it
 * has no way in, which is the opposite of the truth.
 *
 * @return array<string, array{mode:string, addresses:array<int,array{ip:string, ports:string[]}>}>
 */
function stackman_container_net(): array {
  static $net = null;
  if ($net !== null) return $net;

  $net = [];
  if (!stackman_docker_running()) return $net;

  // A REAL tab, not the two characters \t.
  //
  // `docker ps --format` translates \t into a tab; `docker inspect --format`
  // does NOT, and prints it literally. The two commands do not agree, and the
  // difference is silent — every line comes back as one field, every row is
  // discarded for being too short, and the answer is simply an empty list with
  // no error anywhere. Written this way it cannot be got wrong by eye.
  $tab = "\t";

  // Every map is reached with `index`, never with dotted notation.
  //
  // `.Config.ExposedPorts` looks equivalent to `index .Config "ExposedPorts"`
  // and is not. Docker omits that key entirely for a container that exposes no
  // ports, and dotted access to a key that is ABSENT — as opposed to empty —
  // fails the whole record with "map has no entry for key". On this server that
  // silently dropped seven containers out of seventy-nine, including every
  // host-network one. `index` returns nothing for a missing key instead.
  //
  // Ranging over the result is then safe either way: `range` over nothing
  // produces nothing, where `len` would raise its own error on nil.
  //
  // The trailing "end" is not decoration. PHP's exec() TRIMS TRAILING
  // WHITESPACE from every line it collects, and a container with neither
  // published nor exposed ports ends its record with two empty fields — so the
  // two tabs holding them open are trimmed away and the row arrives with three
  // fields instead of five. It is then discarded for being malformed. That is
  // what silently lost the same seven containers a second time, after the
  // template itself had been fixed. A field that is never empty at the end
  // means there is no trailing whitespace to lose.
  $fmt = '{{.Id}}'.$tab.'{{.HostConfig.NetworkMode}}'.$tab
       . '{{range $k, $v := index .NetworkSettings "Networks"}}{{$v.IPAddress}},{{end}}'.$tab
       . '{{range $p, $b := index .NetworkSettings "Ports"}}{{range $b}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}'.$tab
       . '{{range $p, $v := index .Config "ExposedPorts"}}{{$p}},{{end}}'.$tab.'end';

  // Piped rather than two round trips. One container on this server is in a
  // broken state and makes inspect print an error and exit non-zero; the other
  // containers are still reported, so the output is used regardless of the exit
  // code and stderr is discarded.
  $docker = escapeshellarg(stackman_docker_bin());
  $out    = stackman_sh(
    $docker.' ps -aq | xargs -r '.$docker.' inspect --format '.escapeshellarg($fmt), 20
  );

  $hostIp = stackman_host_ip();

  foreach (explode("\n", $out) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 5) continue;

    [$id, $mode, $ips, $bindings, $exposed] = $c;
    if ($id === '') continue;

    // ---- ports forwarded from this server ----
    $byIp = [];
    foreach (preg_split('/\s+/', trim($bindings)) as $bind) {
      if ($bind === '') continue;
      // Split on the LAST colon: an IPv6 host address contains colons of its own.
      $at = strrpos($bind, ':');
      if ($at === false) continue;
      $ip   = substr($bind, 0, $at);
      $port = substr($bind, $at + 1);
      if ($port === '') continue;

      // 0.0.0.0 and :: both mean "every address on this machine", and docker
      // reports the same port under both. Folding them together is what stops
      // every port appearing twice.
      if ($ip === '0.0.0.0' || $ip === '::' || $ip === '') $ip = $hostIp;
      $byIp[$ip][$port] = true;
    }

    // ---- or the container's own address ----
    if (!$byIp) {
      $ports = [];
      foreach (explode(',', $exposed) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $ports[strtok($p, '/')] = true;      // "8083/tcp" -> "8083"
      }

      if ($mode === 'host') {
        if ($hostIp !== '') $byIp[$hostIp] = $ports;
      } else {
        foreach (explode(',', $ips) as $ip) {
          $ip = trim($ip);
          // Docker prints the literal string "invalid IP" for a container whose
          // address is unset. It is not an address and must not be shown.
          if ($ip === '' || $ip === 'invalid IP') continue;
          $byIp[$ip] = $ports;
        }
      }
    }

    // What goes in front of the ports.
    //
    // Bridge and host networking both come out at the same place: THIS server's
    // address. A bridge keeps the container on a private 172.x nobody else can
    // reach and forwards a port from the server; host networking puts the
    // container directly on the server. Either way the address is the one you
    // are already reading this page at, so printing it puts the same string
    // down the whole column and crowds out the part that differs. The NETWORK
    // is what differs — mybridge, a stack's own compose network, or host — so
    // that is named instead, and the address stays on hover.
    //
    // Anything else, Unraid's br0.x macvlan networks above all, gives the
    // container a real address of its own. That address IS the surprising part
    // and is exactly what you would type, so there it leads.
    $driver = stackman_network_drivers()[$mode] ?? '';
    $onHostAddress = in_array($driver, ['bridge', 'host'], true)
                  || in_array($mode, ['default', 'bridge', 'host'], true);

    $addresses = [];
    foreach ($byIp as $ip => $ports) {
      $list = array_keys($ports);
      sort($list, SORT_NUMERIC);

      // The second test is a backstop for any driver not thought of here: if
      // whatever we resolved is simply this server's address, it is hidden for
      // the same reason, whatever kind of network produced it.
      $useName = $onHostAddress || ($hostIp !== '' && $ip === $hostIp);

      $addresses[] = [
        'ip'    => (string)$ip,
        'label' => $useName ? $mode : (string)$ip,
        'ports' => array_map('strval', $list),
      ];
    }

    // The server's own address first — it is the one that usually works from
    // wherever you are reading this.
    usort($addresses, function ($a, $b) use ($hostIp) {
      $rank = fn($x) => $x['ip'] === $hostIp ? 0 : 1;
      return $rank($a) <=> $rank($b) ?: strcmp($a['label'], $b['label']);
    });

    $net[$id] = ['mode' => $mode, 'driver' => $driver, 'addresses' => $addresses];
  }

  return $net;
}

/**
 * The containers belonging to one stack.
 *
 * Its compose file is asked for first, because that ties a container to THIS
 * folder rather than to a name that another stack could also be using. The
 * project name is the fallback for a stack whose file has moved.
 *
 * @param array $s one entry from stackman_list_stacks()
 */
function stackman_stack_containers(array $s): array {
  $index = stackman_container_index();

  if ($s['file'] !== '' && isset($index['byFile'][$s['file']])) {
    return $index['byFile'][$s['file']];
  }

  // The LEAF, never the full path. Compose names a project after the directory
  // holding the file, so a stack in a folder is still "jellyfin" and never
  // "media/jellyfin" — matching on the path would find nothing the moment a
  // stack was filed.
  $leaf    = $s['leaf'] ?? stackman_path_leaf($s['name']);
  $project = $s['project'] !== '' ? $s['project'] : strtolower($leaf);
  return $index['byProject'][$project] ?? [];
}

/** Can anything actually be started? Both halves have to be true. */
function stackman_can_run(): bool {
  return stackman_compose()['available'] && stackman_docker_running();
}

/**
 * The running state of every stack, and nothing else.
 *
 * This is stackman_list_stacks() with the expensive half removed. That function
 * runs `compose config --services` once per stack to list its services, which
 * on a server with a dozen stacks is a dozen separate compose invocations. None
 * of that can change when a stack is started or stopped, so answering "is it
 * up?" is one `compose ls` for the whole machine and no file reads at all.
 *
 * @return array<string, array{name:string, file:string, project:string,
 *                             status:string, running:bool}>
 */
function stackman_stack_states(): array {
  $out = [];

  foreach (stackman_scan_stacks()['stacks'] as $found) {
    $file   = stackman_find_compose_file($found['dir']);
    $state  = stackman_state_for($file, $found['leaf']);
    $status = (string)($state['status'] ?? '');

    $out[$found['rel']] = [
      // name, leaf and file are carried so this can be handed straight to
      // stackman_stack_containers(), which needs all three to find them.
      'name'    => $found['rel'],
      'leaf'    => $found['leaf'],
      'folder'  => $found['folder'],
      'file'    => $file,
      'project' => (string)($state['name'] ?? ''),
      'status'  => $status,
      'running' => stripos($status, 'running') !== false,
    ];
  }

  return $out;
}

/* ------------------------------------------------------------ read/write -- */

function stackman_read_stack(string $name, string &$error): ?string {
  $error = '';
  if (!stackman_valid_path($name)) { $error = 'Invalid stack name.'; return null; }
  $file = stackman_find_compose_file(stackman_stack_dir($name));
  if ($file === '') { $error = 'No compose file found in this stack.'; return null; }
  $body = @file_get_contents($file);
  if ($body === false) { $error = 'Could not read '.$file; return null; }
  return $body;
}

/**
 * Check a compose file the only way that means anything: hand it to compose.
 *
 * The text is written to a scratch file and `config -q` is run over it, which
 * parses it fully and says nothing if it is fine. The error text compose
 * produces is far better than anything we would write, so it is passed
 * straight through to the user.
 */
function stackman_validate_compose(string $yaml, string &$error): bool {
  $error = '';

  if (trim($yaml) === '') { $error = 'The compose file is empty.'; return false; }
  if (!preg_match('/^services:/m', $yaml)) {
    $error = 'This does not look like a compose file — it has no "services:" section.';
    return false;
  }

  $cmd = stackman_compose_cmd();
  if ($cmd === '') return true;   // Nothing to check with; accept and warn elsewhere.

  $tmpdir = @tempnam(sys_get_temp_dir(), 'stackman');
  if ($tmpdir === false) return true;
  @unlink($tmpdir);
  if (!@mkdir($tmpdir, 0700)) return true;

  $tmpfile = $tmpdir.'/compose.yaml';
  @file_put_contents($tmpfile, str_replace("\r\n", "\n", $yaml));

  // Judge this on the exit code, not on whether anything was printed. Compose
  // writes deprecation notices and other warnings to stderr for files that are
  // perfectly valid; treating any output as failure would reject them.
  $lines = [];
  $code  = 1;
  @exec('timeout -k 2 20 '.$cmd.' -f '.escapeshellarg($tmpfile).' config -q </dev/null 2>&1', $lines, $code);

  @unlink($tmpfile);
  @rmdir($tmpdir);

  if ($code === 0) return true;
  if ($code === 124) {
    $error = 'Compose took too long to read this file and was stopped. '
           . 'The file was not saved.';
    return false;
  }

  // Strip the scratch path out of the message; it means nothing to the user.
  $out = trim(str_replace([$tmpfile, $tmpdir], ['your compose file', ''], implode("\n", $lines)));
  $error = $out !== '' ? $out : 'Compose rejected this file (exit code '.$code.').';
  return false;
}

/**
 * Answers "why did nothing happen?" with facts rather than guesses.
 *
 * Reaching this at all proves the endpoint is served and PHP is running. The
 * rest checks the things that silently stop a save: a missing folder, a folder
 * that cannot be written to, a filesystem that is full or mounted read-only.
 *
 * Kept cheap on purpose. A diagnostic that hangs for the same reason as the
 * thing it is diagnosing is useless, so this counts folders itself rather than
 * calling out to compose for each one.
 */
function stackman_selftest(): array {
  $root  = stackman_stack_root();
  $probe = $root.'/.stackman-write-test';

  $scan  = stackman_scan_stacks();
  $dirs  = count($scan['stacks']);
  $folds = count($scan['folders']);

  $canWrite = false;
  $writeErr = '';
  if (!is_dir($root)) {
    $writeErr = 'The stack folder does not exist.';
  } elseif (@file_put_contents($probe, "ok\n") === false) {
    $writeErr = 'The stack folder exists but could not be written to.';
  } else {
    $canWrite = true;
    @unlink($probe);
  }

  // Deliberately runs NO external commands. Everything here is answered by PHP
  // itself, so this reply cannot hang. Anything needing docker or compose is a
  // probe instead — see stackman_probes() — run one at a time so a command
  // that never returns can be identified rather than just suspected.
  $composePath = '';
  foreach (stackman_compose_paths() as $path) {
    if (is_file($path) && is_executable($path)) { $composePath = $path; break; }
  }

  $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

  return [
    'endpoint reachable'  => 'yes — you are reading its reply',
    'php version'         => PHP_VERSION,
    'php interface'       => PHP_SAPI,
    'running as'          => (function_exists('posix_getpwuid') && function_exists('posix_geteuid'))
                               ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                               : get_current_user(),
    'max execution time'  => (string)ini_get('max_execution_time').' s',
    'stack folder'        => $root,
    'folder exists'       => is_dir($root) ? 'yes' : 'NO',
    'folder writable'     => $canWrite ? 'yes' : 'NO — '.$writeErr,
    'free space'          => is_dir($root) && ($free = @disk_free_space($root)) !== false
                               ? round($free / 1048576).' MB'
                               : 'unknown',
    'stacks found'        => (string)$dirs,
    'folders found'       => (string)$folds,
    'dockerd pid file'    => is_file('/var/run/dockerd.pid') ? 'present' : 'MISSING',
    'compose on disk'     => $composePath !== '' ? $composePath : 'not found in known locations',
    'docker on disk'      => stackman_docker_bin(),
    'timeout command'     => is_file('/usr/bin/timeout') ? '/usr/bin/timeout' : 'NOT FOUND',
    'exec() available'    => function_exists('exec') && !in_array('exec', $disabled, true)
                               ? 'yes' : 'NO — nothing can be run',
    'job folder'          => STACKMAN_JOB_DIR,
  ];
}

/**
 * External commands the page depends on, each runnable on its own.
 *
 * "Something hangs" is not a diagnosis. Running these one at a time turns it
 * into "this command hangs", which is.
 *
 * @return array<string, array{label:string, cmd:string, timeout:int}>
 */
function stackman_probes(): array {
  $docker  = escapeshellarg(stackman_docker_bin());
  $fmt     = '{{.Names}}\t{{.Label "com.docker.compose.project"}}';

  return [
    'shell'   => ['label' => 'run a trivial command',   'cmd' => 'echo ok',                    'timeout' => 5],
    'timeout' => ['label' => 'timeout is usable',       'cmd' => 'timeout 2 echo ok',          'timeout' => 5],
    'docker'  => ['label' => 'docker client responds',  'cmd' => $docker.' --version',         'timeout' => 8],
    'daemon'  => ['label' => 'docker daemon responds',  'cmd' => $docker.' info --format "{{.ServerVersion}}"', 'timeout' => 10],
    'compose' => ['label' => 'compose responds',        'cmd' => $docker.' compose version --short', 'timeout' => 10],
    'ps'      => ['label' => 'list containers',         'cmd' => $docker.' ps -a --format '.escapeshellarg($fmt), 'timeout' => 10],
    'ls'      => ['label' => 'list compose projects',   'cmd' => $docker.' compose ls --all --format json', 'timeout' => 15],
  ];
}

/**
 * Run one probe and report how it went, including how long it took.
 *
 * @return array{key:string, label:string, ok:bool, ms:int, exit:int, output:string}
 */
function stackman_run_probe(string $key): array {
  $probes = stackman_probes();
  if (!isset($probes[$key])) {
    return ['key' => $key, 'label' => $key, 'ok' => false, 'ms' => 0,
            'exit' => -1, 'output' => 'Unknown probe.'];
  }

  $probe = $probes[$key];
  $lines = [];
  $code  = 1;
  $start = microtime(true);

  // No `timeout` wrapper here beyond what the probe itself specifies — the
  // point is to observe the raw behaviour, including a command that never
  // returns. The browser gives up on its own if this does not come back.
  @exec('timeout -k 2 '.$probe['timeout'].' '.$probe['cmd'].' </dev/null 2>&1', $lines, $code);

  $ms  = (int)round((microtime(true) - $start) * 1000);
  $out = trim(implode("\n", $lines));

  return [
    'key'    => $key,
    'label'  => $probe['label'],
    'ok'     => $code === 0,
    'ms'     => $ms,
    'exit'   => $code,
    'output' => $code === 124
                  ? 'TIMED OUT after '.$probe['timeout'].'s — this is the one that hangs.'
                  : ($out !== '' ? substr($out, 0, 400) : '(no output)'),
  ];
}

/**
 * Write a stack's compose file.
 *
 * The text is written exactly as given. No reformatting, no reordering, no
 * normalising — what you paste is what lands on disk, byte for byte. This is
 * the whole promise of the project and it is enforced here by simply not
 * touching the string.
 */
function stackman_save_stack(string $name, string $yaml, string &$error): bool {
  $error = '';

  if (!stackman_valid_path($name)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return false;
  }
  // A stack may sit one folder down, but the folder has to be there already —
  // mkdir would otherwise invent a folder from a typo in a stack's name.
  $folder = stackman_path_folder($name);
  if ($folder !== '' && !is_dir(stackman_stack_root().'/'.$folder)) {
    $error = 'There is no folder called "'.$folder.'".';
    return false;
  }
  if (!stackman_validate_compose($yaml, $error)) return false;

  $dir = stackman_stack_dir($name);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir;
    return false;
  }

  // Keep an existing file's name rather than imposing ours on it.
  $file = stackman_find_compose_file($dir);
  if ($file === '') $file = $dir.'/compose.yaml';

  // Normalise to Unix line endings. A compose file pasted from a Windows
  // browser can arrive with carriage returns, which compose tolerates but
  // which corrupt shell scripts and block scalars inside the file.
  $yaml = str_replace("\r\n", "\n", $yaml);

  if (@file_put_contents($file, $yaml) === false) {
    $error = 'Could not write '.$file;
    return false;
  }
  @chmod($file, 0644);
  return true;
}

/**
 * Delete a stack directory.
 *
 * Checked before anything is removed, never during. A stack folder is allowed
 * to hold a compose file and .env files; anything else means the user put
 * something there we do not understand, and the safe answer is to refuse and
 * say what is in the way — not to delete half of it and then complain.
 *
 * The containers are stopped first. Deleting the file while the stack is up
 * would leave containers running that nothing on this page can reach.
 */
function stackman_delete_stack(string $name, string &$error): bool {
  $error = '';
  if (!stackman_valid_path($name)) { $error = 'Invalid stack name.'; return false; }

  $dir = stackman_stack_dir($name);
  if (!is_dir($dir)) { $error = 'No such stack.'; return false; }

  $remove = [];
  $blocked = [];
  foreach ((array)@scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $isCompose = in_array($entry, STACKMAN_COMPOSE_FILENAMES, true);
    $isEnv     = $entry === '.env' || str_starts_with($entry, '.env.');
    if (is_file($dir.'/'.$entry) && ($isCompose || $isEnv)) $remove[] = $entry;
    else $blocked[] = $entry;
  }

  if ($blocked) {
    $error = 'Nothing was deleted. This folder also contains: '
           . implode(', ', array_slice($blocked, 0, 6))
           . (count($blocked) > 6 ? ', …' : '')
           . '. Remove it by hand if you are sure.';
    return false;
  }

  $file = stackman_find_compose_file($dir);
  $cmd  = stackman_compose_cmd();
  if ($file !== '' && $cmd !== '' && stackman_docker_running()) {
    $code = 1;
    $out  = stackman_sh(
      'cd '.escapeshellarg($dir).' && '.$cmd.' -f '.escapeshellarg($file).' down 2>&1',
      120,
      $code
    );
    // Refuse to delete the file if the containers are still up. Losing the
    // compose file while its containers run leaves them orphaned, with nothing
    // in this UI able to reach them again.
    if ($code !== 0) {
      $error = 'The containers could not be stopped, so nothing was deleted. '
             . 'Stop the stack first, then try again.'
             . ($out !== '' ? "\n\n".trim($out) : '');
      return false;
    }
  }

  foreach ($remove as $entry) @unlink($dir.'/'.$entry);

  if (!@rmdir($dir)) {
    $error = 'Removed the files, but could not remove the folder '.$dir;
    return false;
  }
  return true;
}

/* -------------------------------------------------------------- run jobs -- */

/**
 * Compose commands this plugin is willing to run, and how.
 *
 * An allowlist rather than anything assembled from user input: the verb comes
 * from a button, and only these eight exist.
 *
 * Most verbs carry two argument strings: `args` for the whole stack and `svc`
 * for a single service. Either one can instead be an array of command
 * strings — a short sequence, run in order — rather than a single string; see
 * `update` below and stackman_start_job(), which is what actually walks that
 * array. Every verb except `update` still uses a single string. Three of
 * those pairs are not the obvious mirror of each other:
 *
 *   down/svc  is `stop`, not `down <service>`. `compose down` tears down the
 *             whole project's containers, networks and default volumes; it
 *             has no per-service meaning. `stop` leaves the container in
 *             place, so a later Start puts it back without recreating it.
 *
 *   up/svc    deliberately drops `--remove-orphans` — that flag is about
 *             containers whose service no longer exists in the file at all,
 *             which has nothing to do with scoping to one service — and just
 *             as deliberately keeps compose's own dependency behaviour:
 *             `up -d demo-frontend` also starts whatever it `depends_on`,
 *             because a front end started without its database is a
 *             container that immediately falls over.
 *
 *   remove    exists only at service scope; `down` already IS the stack-scope
 *             version of removing containers, so a second stack-scope entry
 *             would just be `down` under another name.
 *
 *   update    also exists only at service scope, and its `svc` is not one
 *             command but two, run in sequence: `pull` then `up -d`. A pull
 *             only fetches the new image onto disk — it does not touch the
 *             running container, which keeps using whatever image ID it
 *             already started with. `up -d` is the step that notices the
 *             service's tag now resolves to a different image ID and
 *             recreates the container on it, which is the only reason it is
 *             here at all: without it, "update" would just be `pull` wearing
 *             a more promising name. There is deliberately no stack-scope
 *             `update` — chaining `up -d` after a whole-stack pull could
 *             recreate several containers at once from a single click, which
 *             is a bigger decision than this pass makes, so the stack menu's
 *             "Update images" stays a plain pull; see the note beside that
 *             item in stacks.js.
 *
 * `config` has no service form because nothing in the menu ever asks for one
 * — "resolved settings" is a whole-file question.
 */
function stackman_job_verbs(): array {
  return [
    'up'      => ['args' => 'up -d --remove-orphans', 'svc' => 'up -d',             'label' => 'Start'],
    'down'    => ['args' => 'down',                   'svc' => 'stop',              'label' => 'Stop'],
    'restart' => ['args' => 'restart',                'svc' => 'restart',           'label' => 'Restart'],
    'pull'    => ['args' => 'pull',                   'svc' => 'pull',              'label' => 'Update images'],
    'logs'    => ['args' => 'logs --tail 200',        'svc' => 'logs --tail 200',   'label' => 'Logs'],
    'config'  => ['args' => 'config',                                               'label' => 'Resolved settings'],
    'remove'  => [                                    'svc' => 'rm --stop --force', 'label' => 'Remove container'],
    'update'  => [                                    'svc' => ['pull', 'up -d'],   'label' => 'Update image'],
  ];
}

/**
 * Start a compose command in the background and return a job id.
 *
 * These commands are slow — pulling an image can take minutes — and a web
 * request that waits for them will simply time out. So the command is detached
 * and its output collected in a file, which the page then follows.
 *
 * @param string $service '' for the whole stack, or one compose service name
 *                         to scope the command to a single container.
 */
function stackman_start_job(string $name, string $verb, string &$error, string $service = ''): string {
  $error = '';

  $verbs = stackman_job_verbs();
  if (!isset($verbs[$verb]))       { $error = 'Unknown action.';     return ''; }
  if (!stackman_valid_path($name)) { $error = 'Invalid stack name.'; return ''; }

  $cmd = stackman_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!stackman_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = stackman_stack_dir($name);
  $file = stackman_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }

  // Pick the scope-appropriate argument string, and fail loudly rather than
  // silently falling back to the other scope — running the wrong one of
  // these against the wrong target is exactly the kind of mistake this
  // allowlist exists to make impossible.
  $args = $service !== '' ? ($verbs[$verb]['svc'] ?? '') : ($verbs[$verb]['args'] ?? '');
  // A verb with no form for a given scope simply has no key for it — `update`
  // and `remove` carry no `args` at all — so the `?? ''` above is what
  // actually catches those, in both scopes. The `[]` test alongside it is
  // belt and braces for a verb that one day declares an explicitly empty
  // list of steps rather than omitting the key: an empty chain would
  // otherwise build a command that runs nothing and then reports success.
  if ($args === '' || $args === []) {
    $error = $service !== ''
      ? 'That action cannot be run against a single container.'
      : 'That action cannot be run against a whole stack.';
    return '';
  }
  // Normalise to a list of steps. Every verb but `update` supplies a single
  // string, which becomes a one-element list here and behaves exactly as it
  // always has; `update` supplies ['pull', 'up -d'] already.
  $steps = is_array($args) ? $args : [$args];

  if ($service !== '') {
    // The allowlist for a service name: it must be a key of the services the
    // compose file itself declares, the same shape of guarantee
    // stackman_valid_name() gives for stack names. A regex on the shape of
    // the string would accept a service that simply does not exist in this
    // stack; this is a real membership check against the file.
    $services = stackman_compose_meta($file)['services'];
    if (!isset($services[$service])) {
      $error = 'No service called "'.$service.'" in this stack.';
      return '';
    }
    // Belt and braces on top of that allowlist: even a validated name is
    // quoted before it reaches the shell, rather than trusted to already be
    // shell-safe. Every step gets it appended, not just the first — a
    // multi-step verb like `update` runs `pull` and `up -d` as two separate
    // compose invocations, and both have to be scoped to this one service or
    // the second would quietly act on the whole stack instead.
    $steps = array_map(fn($step) => $step.' '.escapeshellarg($service), $steps);
  }

  if (!is_dir(STACKMAN_JOB_DIR) && !@mkdir(STACKMAN_JOB_DIR, 0755, true)) {
    $error = 'Could not create '.STACKMAN_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STACKMAN_JOB_DIR.'/'.$job.'.log';

  // Each step becomes its own `compose -f <file> <step>` invocation, and the
  // chain below is what actually runs — `update` is `pull` and `up -d` back
  // to back in the same shell command, not two separate jobs. Two things
  // about how they are joined matter enough to spell out:
  //
  // `2>&1` is attached to EVERY step here, not once at the end of the chain.
  // In `A && B 2>&1` the redirect binds only to B — a shell redirection
  // attaches to the command it trails, not to the whole `&&` list — so a
  // failing A would send its error straight to the real stderr, which
  // nothing here is reading, and the output panel would show an empty log
  // for exactly the case the user most needs to read: the pull that failed.
  //
  // The steps are joined with `&&`, not `;`, just as deliberately. `;` would
  // run `up -d` regardless of whether `pull` succeeded, recreating the
  // container on whatever image it already has — doing nothing useful, but
  // reporting success while it does it. `&&` short-circuits on the first
  // failure, so `$?` right after the chain is that failing step's own exit
  // code, and the `STACKMAN_JOB_END $?` line below reports the real failure
  // instead of the exit code of a step that never ran.
  $invocations = array_map(
    fn($step) => $cmd.' -f '.escapeshellarg($file).' '.$step.' 2>&1',
    $steps
  );
  $chain = implode(' && ', $invocations);

  $inner = 'cd '.escapeshellarg($dir).' && '
         . $chain
         . '; echo "'.STACKMAN_JOB_END.' $?"';

  // The log's first line is what the output panel shows above the command's
  // own output, so it has to say what actually ran — service name included,
  // and every step of the chain for a multi-step verb, not just the
  // stack-scoped $verbs[$verb]['args']. Built from the same $steps that were
  // just turned into $invocations above, so the line shown and the command
  // run can never drift apart — e.g. for `update` on service "demo-cache":
  // `$ compose pull 'demo-cache' && compose up -d 'demo-cache'`.
  $shown = implode(' && ', array_map(fn($step) => 'compose '.$step, $steps));
  @file_put_contents($log, '$ '.$shown."\n\n");

  // setsid detaches the command into its own session, and stdin/stdout/stderr
  // are all redirected away from this request. Without that, the background
  // command inherits the web server's connection and PHP waits for it to
  // finish — which is exactly what "run it in the background" was meant to
  // avoid, and what leaves a worker stuck when the command never ends.
  @exec(
    'setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &'
  );

  return $job;
}

/**
 * Read a job's output so far.
 *
 * @return array{text:string, done:bool, exit:?int}
 */
function stackman_job_log(string $job): array {
  if (!preg_match('/^[0-9a-f]{16}$/', $job)) return ['text' => '', 'done' => true, 'exit' => null];

  $log  = STACKMAN_JOB_DIR.'/'.$job.'.log';
  $text = is_file($log) ? (string)@file_get_contents($log) : '';

  $done = false;
  $exit = null;
  if (preg_match('/^'.preg_quote(STACKMAN_JOB_END, '/').' (\d+)\s*$/m', $text, $m)) {
    $done = true;
    $exit = (int)$m[1];
    $text = trim(preg_replace('/^'.preg_quote(STACKMAN_JOB_END, '/').' \d+\s*$/m', '', $text));
  }

  return ['text' => $text, 'done' => $done, 'exit' => $exit];
}

/** Remove job logs older than an hour, so /tmp does not fill up over time. */
function stackman_prune_jobs(): void {
  if (!is_dir(STACKMAN_JOB_DIR)) return;
  foreach ((array)@glob(STACKMAN_JOB_DIR.'/*.log') as $log) {
    if (@filemtime($log) < time() - 3600) @unlink($log);
  }
}
?>
