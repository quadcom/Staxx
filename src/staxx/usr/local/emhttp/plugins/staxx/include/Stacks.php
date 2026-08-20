<?PHP
/* StaXX — the stack model.
 * Copyright 2026, StaXX contributors.
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
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

if (defined('STAXX_JOB_DIR')) return;

// Job logs live in RAM, not on the flash drive. They are noisy, they are
// worthless after a reboot, and the flash drive has a finite number of writes.
define('STAXX_JOB_DIR', '/tmp/staxx/jobs');

// Written to a job's log once the command finishes, followed by its exit code.
// Deliberately unlikely to appear in compose output.
define('STAXX_JOB_END', '###staxx-finished###');

// Compose reads whichever of these it finds first, in this order.
const STAXX_COMPOSE_FILENAMES = [
  'compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml',
];

// A companion file's size cap. The browser reads the file itself and posts
// its content as an ordinary form field, so the whole thing sits in one POST
// body — this keeps that comfortably under PHP's post_max_size (8M on the
// test box).
define('STAXX_FILE_MAX', 262144);   // 256 KiB

/* ------------------------------------------------------------------ paths -- */

function staxx_stack_root(): string {
  $root = trim((string)(staxx_cfg()['STACK_ROOT'] ?? ''));
  if ($root === '') $root = STAXX_CFG_DIR.'/stacks';
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
function staxx_valid_name(string $name): bool {
  if ($name === '' || strlen($name) > 63) return false;
  if (strpos($name, '..') !== false) return false;
  return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name);
}

/**
 * Is this a stack's path — either "name" or "folder/name"?
 *
 * A stack now lives either at the top of the stack root or one folder down, so
 * its identity carries at most one slash. This is the same gate as
 * staxx_valid_name() and NOT a looser version of it: the string is split on
 * "/" and every segment is handed to that function unchanged. Widening the
 * regex to allow a slash would be the obvious way to write this and would also
 * be the bug — one permissive character class is all it takes to turn
 * staxx_stack_dir() into a way out of the stack root.
 *
 * Two segments maximum, deliberately. Folders are one level deep; see the note
 * on staxx_scan_stacks().
 */
function staxx_valid_path(string $rel): bool {
  if ($rel === '' || strlen($rel) > 127) return false;

  $parts = explode('/', $rel);
  if (count($parts) > 2) return false;

  foreach ($parts as $part) {
    if (!staxx_valid_name($part)) return false;
  }
  return true;
}

/**
 * Is this a name we are willing to write into a stack's folder as a
 * companion file — a .env, a certificate, a config snippet?
 *
 * Deliberately separate from staxx_valid_name(): that function requires a
 * leading letter or digit, which is right for a directory name and wrong for
 * a file called ".env". This allows one optional leading dot and otherwise
 * shares the same safe character set, no slash, no traversal.
 */
function staxx_valid_filename(string $file): bool {
  if ($file === '' || strlen($file) > 63) return false;
  if (strpos($file, '..') !== false) return false;
  if (strpos($file, '/') !== false) return false;
  if (!preg_match('/^\.?[A-Za-z0-9][A-Za-z0-9._-]*$/', $file)) return false;

  // Compared lowercased even though the filesystem is case-sensitive: a
  // second compose file in the folder would silently change which one the
  // stack runs, and a near-miss in case ("Compose.YAML") is exactly the sort
  // of thing someone would upload by accident.
  return !in_array(strtolower($file), STAXX_COMPOSE_FILENAMES, true);
}

/** The folder half of a stack's path, or '' when it sits at the top. */
function staxx_path_folder(string $rel): string {
  $at = strpos($rel, '/');
  return $at === false ? '' : substr($rel, 0, $at);
}

/** The stack's own directory name, without its folder. */
function staxx_path_leaf(string $rel): string {
  $at = strrpos($rel, '/');
  return $at === false ? $rel : substr($rel, $at + 1);
}

/**
 * Compose's own normalisation of a project name, not a plain strtolower().
 *
 * Compose lowercases a directory name AND strips anything outside
 * [a-z0-9_-] when it invents a project name from one. A directory called
 * "my.stack" — which staxx_valid_name() happily permits — becomes project
 * "mystack", so every fallback that guesses a project name from a stack's
 * leaf has to run this or the guess misses while the stack is down.
 */
function staxx_project_name(string $leaf): string {
  return preg_replace('/[^a-z0-9_-]/', '', strtolower($leaf));
}

function staxx_stack_dir(string $rel): string {
  return staxx_stack_root().'/'.$rel;
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
function staxx_browse_dirs(string $path): array {
  $root = STAXX_BROWSE_ROOT;
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
 * The name goes through staxx_valid_name(), which forbids a slash — so this
 * can only ever create one folder, directly inside a parent that has already
 * been resolved and checked against the browse root by the same rule browsing
 * uses. Nothing here takes a path apart or puts one together from user text.
 */
function staxx_browse_mkdir(string $parent, string $name, string &$error): string {
  $name = trim($name);
  if (!staxx_valid_name($name)) {
    $error = 'Use letters, numbers, dots, dashes and underscores for a folder name, '
           . 'starting with a letter or a number.';
    return '';
  }

  $root = STAXX_BROWSE_ROOT;
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

/**
 * Make every directory a bind-mount host path needs, the same way
 * staxx_browse_mkdir() makes one: ownership and mode copied from the
 * parent, so a container running as 99:100 can actually write to what gets
 * made for it. $path is already absolute — the caller resolves it (see
 * staxx_resolve_host_path()) before this is reached.
 *
 * Validated the same belt-and-braces way staxx_path_verdict() checks
 * containment: staxx_lexical_path() catches a ".." that walks out of /mnt
 * on the text alone, and staxx_real_ancestor() catches a symlink that
 * resolves outside it — text cannot see a symlink, and realpath() cannot
 * resolve a path whose middle does not exist yet, so both have to agree.
 */
function staxx_make_path(string $path, string &$error): bool {
  $error = '';
  if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
    $error = 'That is not a path this plugin can create.';
    return false;
  }

  $root   = STAXX_BROWSE_ROOT; // /mnt
  $inRoot = fn(string $real) => $real === $root || strpos($real, $root.'/') === 0;

  $lexical = staxx_lexical_path($path);
  if (!$inRoot($lexical)) {
    $error = 'Folders can only be made under '.$root.'.';
    return false;
  }
  $ancestor = staxx_real_ancestor($path);
  if ($ancestor === null || !$inRoot($ancestor)) {
    $error = 'Folders can only be made under '.$root.'.';
    return false;
  }

  // Never invent a share. A path has to be at least three segments below
  // /mnt ("user/appdata/<folder>") — /mnt/user/<share> on its own covers the
  // user, cache and every diskN share layout in one rule. Making a directory
  // directly inside /mnt/user creates a whole new top-level share with
  // whatever defaults happen to apply, and with the array stopped it would
  // land on Unraid's in-memory root instead of the array at all.
  $segments = explode('/', trim(substr($lexical, strlen($root)), '/'));
  if (count($segments) < 3 || $segments[0] === '') {
    $error = 'This would create a new share. Make the share itself on the '
           . 'Shares page first, then this folder can be made inside it.';
    return false;
  }

  if (file_exists($path)) {
    if (is_dir($path)) return true; // already there; nothing to do
    $error = $path.' is a file, not a folder. A volume\'s host side must be a folder.';
    return false;
  }

  // Walk down one level at a time from the deepest existing ancestor, so each
  // new folder copies its OWN parent's owner and mode rather than the top of
  // the tree's — a folder made two levels under appdata should look like
  // appdata, not like /mnt.
  $parts = explode('/', trim($path, '/'));
  $built = '';
  foreach ($parts as $part) {
    $built .= '/'.$part;
    if (is_dir($built)) continue;

    $parent = dirname($built);
    $st     = @stat($parent);
    $mode   = $st ? ($st['mode'] & 0777) : 0777;

    if (!@mkdir($built, $mode)) {
      $error = 'The folder "'.$built.'" could not be created. Check that '
             . $parent.' can be written to.';
      return false;
    }

    // Ownership first, then the mode: chown can clear permission bits. The
    // mode is set again regardless of what mkdir() was given, because the
    // process umask filters it.
    if ($st) { @chown($built, $st['uid']); @chgrp($built, $st['gid']); }
    @chmod($built, $mode);
  }

  return true;
}

/**
 * Deepest existing ancestor of a path, resolved.
 *
 * realpath() returns false both for a path that does not exist and for one
 * that resolves outside an allowed root — the two cases this function has to
 * tell apart. So it walks upward from the path until it finds an ancestor
 * that DOES exist, and resolves that instead. Containment is then decided on
 * the ancestor; whether the path itself exists is a separate question the
 * caller answers afterwards.
 */
/**
 * Collapse "." and ".." on the text of an absolute path, touching no disk.
 *
 * A companion to realpath(), not a substitute: this cannot see a symlink, and
 * realpath cannot resolve a path whose middle does not exist yet. Each covers
 * the other's blind spot, so staxx_path_verdict() requires both to agree.
 *
 * ".." at the root stays at the root, which is what the kernel does — /../etc
 * is /etc, not an error.
 */
function staxx_lexical_path(string $path): string {
  $out = [];
  foreach (explode('/', $path) as $part) {
    if ($part === '' || $part === '.') continue;
    if ($part === '..') { array_pop($out); continue; }
    $out[] = $part;
  }
  return '/'.implode('/', $out);
}

function staxx_real_ancestor(string $path): ?string {
  $walked = $path === '' ? '/' : $path;
  for ($i = 0; $i < 64; $i++) { // a real filesystem never nests this deep
    $real = @realpath($walked);
    if ($real !== false) return $real;
    $parent = dirname($walked);
    if ($parent === $walked) return null; // hit the filesystem root; nothing resolved
    $walked = $parent;
  }
  return null;
}

/**
 * Cheap emptiness test for staxx_path_verdict()'s 'inuse' verdict: stop at
 * the first entry that isn't "." or "..", never build a listing and never
 * recurse — the same "one stat's worth of work per path" spirit
 * staxx_check_paths() holds itself to. An unreadable directory reads as
 * empty; 'ok' is already the safer of the two answers to guess wrong on.
 */
function staxx_dir_has_entries(string $dir): bool {
  $dh = @opendir($dir);
  if ($dh === false) return false;
  while (($entry = readdir($dh)) !== false) {
    if ($entry !== '.' && $entry !== '..') { closedir($dh); return true; }
  }
  closedir($dh);
  return false;
}

/**
 * ok | file | missing | skipped | inuse for one already-combined target,
 * checked against one already-resolved root directory.
 *
 * A path outside the root comes back "skipped", never "missing" — reporting
 * "missing" for something like /etc/shadow would turn this into a way to
 * probe the whole filesystem for what does and does not exist, and "missing"
 * is itself an answer. Containment is checked on the deepest existing
 * ancestor (see staxx_real_ancestor()) so a path that does not exist yet
 * can still be reported as "missing" rather than falling through to
 * "skipped" for every path that doesn't already exist.
 *
 * $checkInUse turns an existing, non-empty folder into "inuse" instead of
 * "ok" — only worth asking for a stack being created, where a folder full of
 * another app's data is a hazard rather than the normal state of the world an
 * existing stack lives in. Off by default so nothing changes for a stack
 * being edited.
 */
function staxx_path_verdict(string $target, string $root, bool $checkInUse = false): string {
  if ($root === '') return 'skipped';
  $inRoot = fn(string $real) => $real === $root || strpos($real, $root.'/') === 0;

  // Collapse "." and ".." on the text first, and refuse anything that walks out
  // of the root that way. realpath() cannot do this job alone: it resolves
  // nothing at all unless every component already exists, so a path through a
  // stack folder that has not been created yet kept its ".." segments
  // unresolved, and the walk below then found its containing ancestor inside
  // the root and let it through.
  //
  // This is an EXTRA gate, never a replacement for the realpath checks — text
  // alone cannot see a symlink, which is the case realpath is there for. Both
  // have to pass.
  if (!$inRoot(staxx_lexical_path($target))) return 'skipped';

  $ancestor = staxx_real_ancestor($target);
  if ($ancestor === null || !$inRoot($ancestor)) return 'skipped';

  $real = @realpath($target);
  if ($real === false) return 'missing'; // ancestor is contained; nothing at the leaf yet
  if (!$inRoot($real)) return 'skipped'; // a symlink resolved outside the root after all

  if (!is_dir($real)) return 'file';
  if ($checkInUse && staxx_dir_has_entries($real)) return 'inuse';
  return 'ok';
}

/**
 * Turn one path exactly as written in a compose file into an absolute path,
 * and say which root it has to land inside. The single resolution step
 * staxx_check_paths() and the make-paths action both need done
 * identically, so what the editor underlines is exactly what the button can
 * create.
 *
 * An absolute path is returned as-is, checked against STAXX_BROWSE_ROOT
 * (/mnt) — the same root staxx_browse_dirs() confines itself to. A
 * relative one resolves against the stack's own folder, checked against the
 * stack root instead; that only has somewhere safe to resolve against when
 * $rel names a real stack, so an invalid $rel is left with nothing to resolve
 * against rather than a guess at what it might have meant.
 *
 * Returns null for anything with nowhere sound to resolve against: a null
 * byte, an empty string, a root that failed to resolve, or a relative path
 * with no valid stack behind it. staxx_check_paths() turns that into
 * "skipped".
 *
 * @return array{path:string, root:string}|null
 */
function staxx_resolve_host_path(string $p, string $rel): ?array {
  if (strpos($p, "\0") !== false) return null;
  $trimmed = trim($p);
  if ($trimmed === '') return null;

  if ($trimmed[0] === '/') {
    // Resolved, not the literal constant: every path this is compared against
    // has been through realpath(), so the root has to be too or the two are
    // not the same kind of string.
    $root = @realpath(STAXX_BROWSE_ROOT);
    return $root === false ? null : ['path' => $trimmed, 'root' => $root];
  }

  if (!staxx_valid_path($rel)) return null;
  $stackRoot = @realpath(staxx_stack_root());
  if ($stackRoot === false) return null;

  return ['path' => staxx_stack_dir($rel).'/'.$trimmed, 'root' => $stackRoot];
}

/**
 * What's really on disk for a batch of paths taken from a compose file
 * someone is editing — the "does this bind-mount exist" hint under the
 * volumes list. $rel is the stack's own path, used to resolve a relative
 * source ("./data") against the stack's own folder.
 *
 * Every path here is arbitrary text someone is typing, so it is treated as
 * hostile — see staxx_resolve_host_path() for the containment rules.
 * Anything that fails to resolve is "skipped", not "missing" — see
 * staxx_path_verdict(). This does no shelling out and no directory
 * listing, only realpath()/is_dir() — one stat's worth of work per path.
 *
 * $checkInUse asks staxx_path_verdict() to report an existing non-empty
 * folder as "inuse" rather than "ok" — pass true only for a stack being
 * created, where that folder is someone else's live data rather than the
 * stack's own. Off by default: an existing stack's volumes are expected to
 * be full of its own data, and flagging those would be noise.
 *
 * @param string[] $paths host paths exactly as written in the compose file
 * @param string   $rel   the editing stack's own relative path, or ''
 * @return array<string, string> original path string => ok|file|missing|skipped|inuse
 */
function staxx_check_paths(array $paths, string $rel = '', bool $checkInUse = false): array {
  $unique = [];
  foreach ($paths as $p) {
    if (!is_string($p)) continue;
    $unique[$p] = true; // de-duplicate, first occurrence wins the slot
  }
  $unique = array_slice(array_keys($unique), 0, 200);

  $out = [];
  foreach ($unique as $p) {
    $resolved = staxx_resolve_host_path($p, $rel);
    $out[$p] = $resolved === null ? 'skipped' : staxx_path_verdict($resolved['path'], $resolved['root'], $checkInUse);
  }
  return $out;
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
function staxx_timezones(): array {
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
function staxx_find_compose_file(string $dir): string {
  foreach (STAXX_COMPOSE_FILENAMES as $f) {
    if (is_file($dir.'/'.$f)) return $dir.'/'.$f;
  }
  return '';
}

/**
 * The main file plus its override, if one sits beside it — Docker's own
 * pairing rule, not a scan of the folder. $main is a FILE PATH, not a
 * directory: staxx_find_compose_file() already answers "what is this
 * stack's one compose file", and this only ever widens that single answer
 * into a pair.
 *
 * Strict on purpose: compose.yaml pairs only with compose.override.*, never
 * with docker-compose.override.* sitting in the same folder. A looser match
 * — anything with "override" in its name — would run a file nobody
 * connected to this stack on purpose.
 *
 * @return string[] [] for '', else [$main] or [$main, $override]
 */
function staxx_compose_files(string $main): array {
  if ($main === '') return [];
  $files = [$main];

  $dot = strrpos($main, '.');
  if ($dot !== false) {
    $base = substr($main, 0, $dot);
    foreach (['yaml', 'yml'] as $ext) {         // at most two is_file() calls
      $candidate = $base.'.override.'.$ext;
      if (is_file($candidate)) { $files[] = $candidate; break; }
    }
  }

  return $files;   // order matters: the override wins only because it comes second
}

/**
 * The `-f` sequence for a set of compose files, already shell-quoted.
 *
 * For one file this is character-for-character what every caller used to
 * write inline as '-f '.escapeshellarg($file) — that identity is what keeps
 * every existing command string unchanged for a single-file stack.
 */
function staxx_compose_file_args(array $files): string {
  return implode(' ', array_map(fn($f) => '-f '.escapeshellarg($f), $files));
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
function staxx_scan_stacks(): array {
  $root = staxx_stack_root();
  $out  = ['stacks' => [], 'folders' => []];
  if (!is_dir($root)) return $out;

  foreach ((array)@scandir($root) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (!staxx_valid_name($entry)) continue;

    $dir = $root.'/'.$entry;
    if (!is_dir($dir)) continue;

    if (staxx_find_compose_file($dir) !== '') {
      $out['stacks'][] = ['rel' => $entry, 'dir' => $dir, 'folder' => '', 'leaf' => $entry];
      continue;
    }

    $out['folders'][] = $entry;

    foreach ((array)@scandir($dir) as $kid) {
      if ($kid === '.' || $kid === '..') continue;
      if (!staxx_valid_name($kid)) continue;

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
function staxx_folder_names(): array {
  return staxx_scan_stacks()['folders'];
}

/* --------------------------------------------------------------- compose -- */

/**
 * The compose command to run, already shell-quoted, or '' if unavailable.
 * Either `/usr/bin/docker compose` or the standalone `docker-compose`.
 */
function staxx_compose_cmd(): string {
  $info = staxx_compose();
  if (!$info['available']) return '';
  if ($info['form'] === 'standalone') {
    return $info['path'] !== '' ? escapeshellarg($info['path']) : 'docker-compose';
  }
  return escapeshellarg(staxx_docker_bin()).' compose';
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
function staxx_compose_ls(): array {
  return staxx_compose_state()['byFile'];
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
function staxx_compose_state(): array {
  static $state = null;
  if ($state !== null) return $state;
  $state = ['byFile' => [], 'byTail' => [], 'byName' => []];

  $cmd = staxx_compose_cmd();
  if ($cmd === '' || !staxx_docker_running()) return $state;

  $json = staxx_sh($cmd.' ls --all --format json', 15);
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
      $state['byTail'][staxx_file_tail($file)] = $entry;
    }
  }
  return $state;
}

/** "…/stacks/Media/jellyfin/compose.yaml" -> "jellyfin/compose.yaml". */
function staxx_file_tail(string $file): string {
  return basename(dirname($file)).'/'.basename($file);
}

/**
 * What compose knows about one stack: by its file, then by its tail, then by
 * its project name. See staxx_compose_state() for why there are three.
 *
 * @param string $file absolute path to the compose file, or ''
 * @param string $leaf the stack's own directory name, without its folder
 */
function staxx_state_for(string $file, string $leaf): ?array {
  $state = staxx_compose_state();
  if ($file === '') return $state['byName'][staxx_project_name($leaf)] ?? null;

  if (isset($state['byFile'][$file])) return $state['byFile'][$file];

  $tail = staxx_file_tail($file);
  if (isset($state['byTail'][$tail])) return $state['byTail'][$tail];

  return $state['byName'][staxx_project_name($leaf)] ?? null;
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
function staxx_yaml_flatten(string $yaml): array {
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
 * The first published port of every service, read from the same
 * `docker compose config` text staxx_compose_meta() already has in hand.
 *
 * staxx_yaml_flatten() SKIPS every sequence on purpose (see its own $skip
 * handling), so a `ports:` list is invisible to it — and extending it to read
 * one would affect every caller that relies on sequences being skipped. This
 * is a second, narrow pass over the same text instead, understanding nothing
 * except the one shape `docker compose config` always normalises a port to:
 *
 *   ports:
 *     - mode: host
 *       host_ip: 127.0.0.1
 *       target: 80
 *       published: "15114"
 *       protocol: tcp
 *     - mode: ingress
 *       target: 443
 *
 * Only the first entry under each service is read; the rest are skipped once
 * found, deliberately — see PLAN_39 for why the first port is the one that
 * matters.
 *
 * @return array<string, array{target:string, published:string}> service name
 *         => its first port. A service that publishes none has no entry.
 */
function staxx_first_ports(string $yaml): array {
  $out = [];

  $atServices    = false;   // have we reached the top-level services: block
  $service       = null;    // the service whose ports: is being read
  $serviceIndent = null;    // indent of a service's own key, e.g. 2

  $inPorts     = false;     // inside THIS service's ports: sequence
  $portsIndent = null;      // indent of the 'ports:' key itself
  $itemIndent  = null;      // indent of the '- ' that opened the first item
  $donePorts   = false;     // first item already read; ignore any more
  $target = ''; $published = '';

  // Saved whenever we are about to leave the ports block, however that
  // happens — a sibling key, the next service, or the end of services: — so
  // it only needs writing in one place.
  $save = function () use (&$out, &$service, &$target, &$published) {
    if ($service !== null && $target !== '') {
      $out[$service] = ['target' => $target, 'published' => $published];
    }
  };

  foreach (explode("\n", $yaml) as $raw) {
    $line = rtrim($raw, "\r");
    if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

    $indent = strlen($line) - strlen(ltrim($line, ' '));
    $body   = ltrim($line, ' ');

    if (!$atServices) {
      if ($indent === 0 && $body === 'services:') $atServices = true;
      continue;
    }

    if ($indent === 0) { $save(); break; }   // a sibling of services: — done

    if ($serviceIndent === null) $serviceIndent = $indent;

    if ($indent === $serviceIndent) {
      $save();
      $service     = rtrim($body, ':');
      $inPorts     = false; $portsIndent = null; $itemIndent = null;
      $donePorts   = false; $target = ''; $published = '';
      continue;
    }

    if ($service === null) continue;

    if ($indent === $serviceIndent + 2) {
      // A direct property of the service — only 'ports:' matters here.
      $save();
      $inPorts     = ($body === 'ports:');
      $portsIndent = $indent;
      $itemIndent  = null; $donePorts = false;
      $target = ''; $published = '';
      continue;
    }

    if (!$inPorts || $indent <= $portsIndent || $donePorts) continue;

    $isItem = strncmp($body, '- ', 2) === 0 || $body === '-';

    if ($isItem) {
      if ($itemIndent === null) {
        $itemIndent = $indent;               // the first item — read it
      } elseif ($indent === $itemIndent) {
        $donePorts = true;                   // a second item — stop here
        continue;
      }
      $body = ltrim(substr($body, 1));       // drop the leading '-'
    }

    if (preg_match('/^(target|published)\s*:\s*(.*)$/', $body, $m)) {
      $value = trim($m[2]);
      if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")) {
        $value = substr($value, 1, -1);
      }
      if ($m[1] === 'target') $target = $value; else $published = $value;
    }
  }

  $save();   // services: was the last thing in the file — nothing dedented to catch it

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
 *                                            x:array<string,string>, fixedIp:string,
 *                                            firstPort:array{target?:string,published?:string}}>}
 */
function staxx_compose_meta(string $file, ?string &$error = null): array {
  static $cache = [];

  // Keyed on the whole pair, not just $file, so an override's settings are
  // reflected in what this reports. Safe as a cache key: for a single file
  // it IS the path, byte for byte, so every existing entry still resolves
  // identically — and "\0" can never appear in a path, so a pair can never
  // collide with a lone file's own key.
  $files = staxx_compose_files($file);
  $key   = implode("\0", $files);
  if (isset($cache[$key])) { $error = $cache[$key]['error']; return $cache[$key]; }

  $meta = ['ok' => false, 'error' => null, 'x' => [], 'services' => []];

  $cmd  = staxx_compose_cmd();
  $yaml = '';

  if ($cmd !== '') {
    $code = 1;
    $out  = staxx_sh($cmd.' '.staxx_compose_file_args($files).' config 2>&1', 15, $code);
    if ($code !== 0) {
      // Compose is installed and it rejected the file. Report that rather than
      // falling through to the rough read below — guessing at a broken file
      // produces a list of things that are not services, which reads as though
      // the file were fine.
      $meta['error'] = trim($out) !== '' ? trim($out) : 'Compose could not read this file.';
      $error = $meta['error'];
      return $cache[$key] = $meta;
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

  foreach (staxx_yaml_flatten($yaml) as $path => $value) {
    $parts = explode("\0", $path);

    if ($parts[0] === 'x-unraid' && count($parts) === 2) {
      $meta['x'][$parts[1]] = $value;
      continue;
    }

    if ($parts[0] !== 'services' || count($parts) < 3) continue;
    $service = $parts[1];
    if (!isset($meta['services'][$service])) {
      $meta['services'][$service] = ['image' => '', 'container_name' => '', 'x' => [],
                                      'fixedIp' => '', 'firstPort' => []];
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
    } elseif ($parts[2] === 'networks' && count($parts) === 5 && $parts[4] === 'ipv4_address') {
      // A service on more than one network with more than one fixed address
      // is not a case worth ranking — the first one found wins.
      if ($meta['services'][$service]['fixedIp'] === '') {
        $meta['services'][$service]['fixedIp'] = $value;
      }
    }
  }

  // A separate pass: ports live in a sequence, which the flattener above
  // never sees at all (see staxx_first_ports()'s own comment for why).
  foreach (staxx_first_ports($yaml) as $service => $port) {
    if (!isset($meta['services'][$service])) {
      $meta['services'][$service] = ['image' => '', 'container_name' => '', 'x' => [],
                                      'fixedIp' => '', 'firstPort' => []];
    }
    $meta['services'][$service]['firstPort'] = $port;
  }

  $error = null;
  return $cache[$key] = $meta;
}

/**
 * Service names declared in a compose file.
 *
 * Kept as its own function because most callers want nothing else; the reading
 * itself happens once, in staxx_compose_meta(), and is cached there.
 *
 * @return string[]
 */
function staxx_service_names(string $file, ?string &$error = null): array {
  return array_keys(staxx_compose_meta($file, $error)['services']);
}

/* ----------------------------------------------------------------- stacks -- */

/**
 * Every stack on disk, with whatever compose knows about its state.
 *
 * `name` is the folder on disk and is the stack's identity — every command, the
 * folders file and every selector on the page are keyed on it. `leaf` is what
 * the row is labelled with: the stack's name IS its directory, full stop —
 * there is no separate display-name override any more.
 *
 * @return array<int, array{name:string, leaf:string, dir:string, file:string,
 *                          filename:string, services:string[], x:array<string,string>,
 *                          project:string, status:string, running:bool, hasFile:bool,
 *                          parses:bool, error:?string, review:bool, handover:bool}>
 */
function staxx_list_stacks(): array {
  $stacks = [];

  foreach (staxx_scan_stacks()['stacks'] as $found) {
    $dir    = $found['dir'];
    $file   = staxx_find_compose_file($dir);
    // Locked stacks report no state — same reasoning, and the same one-line
    // guard, as staxx_stack_states(); see its comment for why a green row on
    // an unreviewed import is the hazard rather than a cosmetic problem.
    // Both paths need it: this one draws the row, that one repaints it on
    // every poll, so guarding only one leaves the row turning green seconds
    // after it is drawn.
    $locked = staxx_review_file($dir) !== '';
    $state  = $locked ? null : staxx_state_for($file, $found['leaf']);
    $status = $state['status'] ?? '';

    $parseError = null;
    $meta       = $file !== ''
      ? staxx_compose_meta($file, $parseError)
      : ['ok' => false, 'error' => null, 'x' => [], 'services' => []];
    $services   = array_keys($meta['services']);

    $stacks[] = [
      // The stack's identity is its path under the root — "jellyfin" at the
      // top, or "Media/jellyfin" one folder down. Every command, every DOM
      // attribute and every selector on the page is keyed on it.
      'name'     => $found['rel'],
      'folder'   => $found['folder'],
      'leaf'     => $found['leaf'],
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
      // Imported and not yet reviewed — see the "review lock" section below.
      'review'   => $locked,
      // Waiting to be confirmed after a handover — see the "handover"
      // section below. Never true at the same time as 'review' in the
      // steady state: starting a handover moves the review note aside, and
      // only a failed undo writes a fresh one back, at which point the
      // state file is what the job removes last.
      'handover' => staxx_handover_file($dir) !== '',
    ];
  }

  // Sorted by what the page shows, not by what is on disk, or the list reads as
  // though it were in no order at all. The full path breaks a tie so two
  // stacks sharing a leaf name (one in a folder, one without) keep a stable
  // order between refreshes.
  usort($stacks, function ($a, $b) {
    $by = strnatcasecmp($a['leaf'], $b['leaf']);
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
function staxx_container_index(): array {
  static $index = null;
  if ($index !== null) return $index;

  $index = ['byFile' => [], 'byProject' => []];
  if (!staxx_docker_running()) return $index;

  // `.Label "key"` looks up one label. `index .Labels "key"` does not work in a
  // docker ps template — see the note in staxx_containers_by_project().
  //
  // The trailing "end" holds the last field open: PHP's exec() trims trailing
  // whitespace from each line, so a container whose final label is empty would
  // lose the tab before it and arrive one field short. See the longer note in
  // staxx_container_net().
  //
  // Unlike `docker inspect`, `docker ps --format` DOES translate \t into a tab,
  // which is why this one can be written the readable way.
  $fmt = '{{.ID}}\t{{.Names}}\t{{.State}}\t{{.Status}}\t{{.Image}}\t'
       . '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.service"}}\t'
       . '{{.Label "com.docker.compose.project.config_files"}}\tend';

  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
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
    // under each, the same way staxx_compose_ls() does it.
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
function staxx_host_ip(): string {
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
 * The address that opens a service's own web page, or '' when there is none
 * to open.
 *
 * `x-unraid.webui` supplies the scheme and any path — `https://`, a trailing
 * `/admin` — but its `[PORT:nnn]` number cannot be trusted: checked against 64
 * real templates it names the host port 10 times, the container port 15
 * times, and neither 3 times. Template authors do not agree with each other,
 * so the number inside the token is ignored outright (see PLAN_39) and StaXX
 * always substitutes the service's own first published port instead.
 *
 * @param array  $service one entry of staxx_compose_meta()['services']
 * @param string $hostIp  staxx_host_ip()
 */
function staxx_webui_url(array $service, string $hostIp): string {
  $raw = trim((string)($service['x']['webui'] ?? ''));
  if ($raw === '') return '';

  $fixedIp = (string)($service['fixedIp'] ?? '');
  $address = $fixedIp !== '' ? $fixedIp : $hostIp;

  if (strpos($raw, '[IP]') !== false) {
    if ($address === '') return '';
    $raw = str_replace('[IP]', $address, $raw);
  }

  if (strpos($raw, '[PORT:') !== false) {
    $firstPort = $service['firstPort'] ?? [];
    // A service with its own fixed address publishes nothing to the host —
    // Docker ignores the host half of the mapping entirely for it — so the
    // container port is the only one that can ever answer there.
    $port = $fixedIp !== ''
      ? (string)($firstPort['target'] ?? '')
      : (string)($firstPort['published'] ?? '');
    if ($port === '') return '';
    $raw = preg_replace('/\[PORT:[^\]]*\]/', $port, $raw);
  }

  // Whatever came out has to actually be a web address. Without this check a
  // webui typed by hand — or one left with a stray token nothing replaced —
  // could turn a row button into something other than a link.
  return preg_match('/^https?:\/\//i', $raw) ? $raw : '';
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
function staxx_network_drivers(): array {
  static $drivers = null;
  if ($drivers !== null) return $drivers;

  $drivers = [];
  if (!staxx_docker_running()) return $drivers;

  // `docker network ls` shares the formatter used by `docker ps`, which does
  // translate \t. The trailing "end" guards against exec() trimming a line
  // whose last field is empty — see staxx_container_net().
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' network ls --format '
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
function staxx_container_net(): array {
  static $net = null;
  if ($net !== null) return $net;

  $net = [];
  if (!staxx_docker_running()) return $net;

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
  $docker = escapeshellarg(staxx_docker_bin());
  $out    = staxx_sh(
    $docker.' ps -aq | xargs -r '.$docker.' inspect --format '.escapeshellarg($fmt), 20
  );

  $hostIp = staxx_host_ip();

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
    $driver = staxx_network_drivers()[$mode] ?? '';
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
 * @param array $s one entry from staxx_list_stacks()
 */
function staxx_stack_containers(array $s): array {
  // A stack awaiting review owns nothing, by definition — it was imported and
  // nobody has confirmed what it refers to. The project-name fallback below
  // would otherwise hand it the LIVE containers of whatever it was copied
  // from, and the row would then show their state, address, processor and
  // memory as though the import were already working. Guarded here rather
  // than at each of the three call sites so the full render and the cheap
  // state refresh cannot disagree about it.
  if (isset($s['name']) && staxx_review_locked((string)$s['name'])) return [];

  $index = staxx_container_index();

  if ($s['file'] !== '' && isset($index['byFile'][$s['file']])) {
    return $index['byFile'][$s['file']];
  }

  // The LEAF, never the full path. Compose names a project after the directory
  // holding the file, so a stack in a folder is still "jellyfin" and never
  // "media/jellyfin" — matching on the path would find nothing the moment a
  // stack was filed.
  $leaf    = $s['leaf'] ?? staxx_path_leaf($s['name']);
  $project = $s['project'] !== '' ? $s['project'] : staxx_project_name($leaf);
  return $index['byProject'][$project] ?? [];
}

/** Can anything actually be started? Both halves have to be true. */
function staxx_can_run(): bool {
  return staxx_compose()['available'] && staxx_docker_running();
}

/**
 * The running state of every stack, and nothing else.
 *
 * This is staxx_list_stacks() with the expensive half removed. That function
 * runs `compose config --services` once per stack to list its services, which
 * on a server with a dozen stacks is a dozen separate compose invocations. None
 * of that can change when a stack is started or stopped, so answering "is it
 * up?" is one `compose ls` for the whole machine and no file reads at all.
 *
 * @return array<string, array{name:string, file:string, project:string,
 *                             status:string, running:bool}>
 */
function staxx_stack_states(): array {
  $out = [];

  foreach (staxx_scan_stacks()['stacks'] as $found) {
    $file   = staxx_find_compose_file($found['dir']);
    // A locked stack is reported as having no state at all, and deliberately.
    // staxx_state_for() falls back to matching on the project name, which
    // compose derives from the folder — so an imported copy sitting in a
    // folder named after the project it came from matches the LIVE one and
    // the row goes green for containers that are not ours. That green row is
    // the mechanism of the whole hazard the review lock exists to stop, so it
    // must not appear even though the verbs behind it are already refused.
    $state  = staxx_review_file($found['dir']) !== '' ? null
                                                     : staxx_state_for($file, $found['leaf']);
    $status = (string)($state['status'] ?? '');

    $out[$found['rel']] = [
      // name, leaf and file are carried so this can be handed straight to
      // staxx_stack_containers(), which needs all three to find them.
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

function staxx_read_stack(string $name, string &$error): ?string {
  $error = '';
  if (!staxx_valid_path($name)) { $error = 'Invalid stack name.'; return null; }
  $file = staxx_find_compose_file(staxx_stack_dir($name));
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
 *
 * $dir is the stack's real folder. When it exists, it is passed to compose as
 * `--project-directory` so a relative `env_file:` or a `.env` sitting beside
 * the real compose file can resolve — measured on the test box, without this
 * an ordinary file using env_file: was refused with "env file not found"
 * even though it was perfectly valid. A new stack has no folder yet, so $dir
 * is '' and the flag is simply omitted.
 *
 * There is no local "does this look like a compose file" check any more — an
 * include:-only file with no services: key is valid and compose accepts it,
 * so a services: gate was refusing good files. That means the guard below
 * (nothing to validate with, so accept) now waves through ANY non-empty text
 * when compose cannot be found, where previously the services: check still
 * caught some nonsense in that case. Compose missing is already a broken
 * install, so this trade only matters on a server in that state.
 *
 * $warnings, if passed by reference, is filled with compose's own advisory
 * notes on a file that PASSES — e.g. an unset variable defaulting to blank.
 * These are informational only and never turn a pass into a failure.
 *
 * $before and $after are real, already-on-disk files placed before/after the
 * text under test in the `-f` sequence — the companion half of a two-file
 * stack. Neither half of a pair is ever validated alone, because the pair is
 * what compose actually runs; the caller decides which slot the text under
 * test belongs in (main file being checked -> its override, if any, goes in
 * $after; an override being checked -> the real main file goes in $before).
 */
// $warnings is ?array, not array: PHP 8.4 (which Unraid 7.2 ships) deprecates
// implicitly nullable parameters, and a deprecation notice on every save would
// land inside action.php's JSON reply.
function staxx_validate_compose(string $yaml, string &$error, string $dir = '', ?array &$warnings = null,
                                 string $before = '', string $after = ''): bool {
  $error = '';
  $warnings = [];

  if (trim($yaml) === '') { $error = 'The compose file is empty.'; return false; }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') return true;   // Nothing to check with; accept and warn elsewhere.

  $tmpdir = @tempnam(sys_get_temp_dir(), 'staxx');
  if ($tmpdir === false) return true;
  @unlink($tmpdir);
  if (!@mkdir($tmpdir, 0700)) return true;

  $tmpfile = $tmpdir.'/compose.yaml';
  @file_put_contents($tmpfile, str_replace("\r\n", "\n", $yaml));

  $projectFlag = ($dir !== '' && is_dir($dir))
    ? '--project-directory '.escapeshellarg($dir).' '
    : '';

  // The temp file's own place in the sequence is what makes it the main
  // file or the override under test — with both empty this reproduces the
  // exact command a single-file stack has always run.
  $fileArgs = '';
  if ($before !== '') $fileArgs .= '-f '.escapeshellarg($before).' ';
  $fileArgs .= '-f '.escapeshellarg($tmpfile).' ';
  if ($after !== '') $fileArgs .= '-f '.escapeshellarg($after).' ';

  // Judge this on the exit code, not on whether anything was printed. Compose
  // writes deprecation notices and other warnings to stderr for files that are
  // perfectly valid; treating any output as failure would reject them.
  $lines = [];
  $code  = 1;
  @exec('timeout -k 2 20 '.$cmd.' '.$projectFlag.$fileArgs.'config -q </dev/null 2>&1', $lines, $code);

  @unlink($tmpfile);
  @rmdir($tmpdir);

  if ($code === 0) {
    // Compose still writes advisory notes to stderr for a file it accepts —
    // logfmt, one per line. Pull the msg="…" payload out of the warning lines
    // only; anything that does not match this shape is never shown, so raw
    // logfmt never reaches a user.
    foreach ($lines as $line) {
      if (preg_match('/level=warning msg="((?:[^"\\\\]|\\\\.)*)"/', $line, $m)) {
        $warnings[] = str_replace('\\"', '"', $m[1]);
      }
    }
    return true;
  }
  if ($code === 124) {
    $error = 'Compose took too long to read this file and was stopped. '
           . 'The file was not saved.';
    return false;
  }

  // Strip the scratch path out of the message; it means nothing to the user.
  // When $before is set, the text under test IS the override, so the message
  // has to say so rather than call it "your compose file".
  $out = trim(str_replace(
    [$tmpfile, $tmpdir],
    [$before !== '' ? 'your override file' : 'your compose file', ''],
    implode("\n", $lines)
  ));
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
function staxx_selftest(): array {
  $root  = staxx_stack_root();
  $probe = $root.'/.staxx-write-test';

  $scan  = staxx_scan_stacks();
  $dirs  = count($scan['stacks']);
  $folds = count($scan['folders']);

  // Pure PHP, like everything else here — no external command, so this stays
  // instant and checkable from the server with no browser.
  $awaitingReview = 0;
  foreach ($scan['stacks'] as $found) {
    if (staxx_review_file($found['dir']) !== '') $awaitingReview++;
  }

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
  // probe instead — see staxx_probes() — run one at a time so a command
  // that never returns can be identified rather than just suspected.
  $composePath = '';
  foreach (staxx_compose_paths() as $path) {
    if (is_file($path) && is_executable($path)) { $composePath = $path; break; }
  }

  $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

  /* What could be imported, counted the only way this function is allowed to
   * count anything: by looking at the disk.
   *
   * NOT by calling Import.php's own readers. Those ask docker which containers
   * exist and compose what each project holds — and this function's whole
   * promise is that it runs no external command and therefore cannot hang,
   * because it is what you reach for WHEN docker is hanging. A self-test that
   * blocks on the thing being diagnosed is worse than no self-test.
   *
   * So these are file counts, and they are honest about being file counts: a
   * template here is an .xml on disk, not one that has been parsed. The
   * "belongs to neither" figure has no disk answer at all — it needs to ask
   * docker what is running — so it is named as unavailable rather than
   * quietly reported as zero. Paths are spelled out rather than read from
   * Import.php's constants, which are not defined when only this file is
   * loaded (see the troubleshooting one-liner in stacks.js). */
  $tplDir  = '/boot/config/plugins/dockerMan/templates-user';
  $projDir = '/boot/config/plugins/compose.manager/projects';

  $templatesReport = is_dir($tplDir)
    ? (string)count((array)@glob($tplDir.'/*.xml'))
    : 'none — no Unraid template folder on this server';

  // Resolved the same two disk-only ways staxx_import_resolve_project_file()
  // tries first — an 'indirect' file naming the real folder, or the
  // project's own folder — but never its third way, which asks Docker which
  // running container's label points at the file. That means a project only
  // findable through a running container's label counts as unresolved here,
  // which is an honest gap rather than a wrong answer: it is what "no
  // external command" costs.
  $projectsResolved   = 0;
  $projectsUnresolved = 0;
  if (is_dir($projDir)) {
    foreach ((array)@scandir($projDir) as $entry) {
      if ($entry === '.' || $entry === '..') continue;
      $dir = $projDir.'/'.$entry;
      if (!is_dir($dir)) continue;

      $indirect = $dir.'/indirect';
      $file     = '';
      if (is_file($indirect)) {
        $target = rtrim(trim((string)@file_get_contents($indirect)), '/');
        if ($target !== '') $file = staxx_find_compose_file($target);
      } else {
        $file = staxx_find_compose_file($dir);
      }

      if ($file !== '' && is_readable($file)) $projectsResolved++;
      else $projectsUnresolved++;
    }
  }
  $projectsReport = is_dir($projDir)
    ? $projectsResolved.' resolve to a readable compose file, '.$projectsUnresolved.' do not '
      . '(a project only found by asking Docker counts as "do not" here, since this check never does)'
    : 'none — Compose Manager is not installed';

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
    'stacks awaiting review' => (string)$awaitingReview,
    'unraid templates on disk'  => $templatesReport,
    'compose manager projects on disk' => $projectsReport,
    'containers matching neither' => 'needs docker — see the Import panel, not this list',
    'dockerd pid file'    => is_file('/var/run/dockerd.pid') ? 'present' : 'MISSING',
    'compose on disk'     => $composePath !== '' ? $composePath : 'not found in known locations',
    'docker on disk'      => staxx_docker_bin(),
    'timeout command'     => is_file('/usr/bin/timeout') ? '/usr/bin/timeout' : 'NOT FOUND',
    'exec() available'    => function_exists('exec') && !in_array('exec', $disabled, true)
                               ? 'yes' : 'NO — nothing can be run',
    'job folder'          => STAXX_JOB_DIR,
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
function staxx_probes(): array {
  $docker  = escapeshellarg(staxx_docker_bin());
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
function staxx_run_probe(string $key): array {
  $probes = staxx_probes();
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
function staxx_save_stack(string $name, string $yaml, string &$error): bool {
  $error = '';

  if (!staxx_valid_path($name)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return false;
  }
  // A stack may sit one folder down, but the folder has to be there already —
  // mkdir would otherwise invent a folder from a typo in a stack's name.
  $folder = staxx_path_folder($name);
  if ($folder !== '' && !is_dir(staxx_stack_root().'/'.$folder)) {
    $error = 'There is no folder called "'.$folder.'".';
    return false;
  }
  $dir = staxx_stack_dir($name);

  // The stack's existing override, if it has one, has to be checked
  // alongside whatever is being saved here — a main file that is fine on
  // its own can still be broken once the override on disk is layered over
  // it. A brand new stack has neither a folder nor an override yet, so
  // $after comes back '' and this is a no-op for it.
  $after = staxx_compose_files(staxx_find_compose_file($dir))[1] ?? '';

  // Pass the stack's real folder so relative env_file: paths and a sibling
  // .env can resolve during validation — see staxx_validate_compose(). A
  // new stack's folder does not exist yet; that is fine, the flag is just
  // omitted for it.
  $warnings = null;
  if (!staxx_validate_compose($yaml, $error, $dir, $warnings, '', $after)) {
    if ($after !== '') {
      $error .= "\n\nThis stack has an override file, and the two are checked together.";
    }
    return false;
  }

  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir;
    return false;
  }

  // Keep an existing file's name rather than imposing ours on it.
  $file = staxx_find_compose_file($dir);
  if ($file === '') $file = $dir.'/compose.yaml';

  if (@file_put_contents($file, $yaml) === false) {
    $error = 'Could not write '.$file;
    return false;
  }
  @chmod($file, 0644);
  return true;
}

/**
 * Everything in a stack's folder except its compose file, for the delete
 * confirmation. A subdirectory's count is how many entries it holds one
 * level down, so the confirmation can say "3 files" rather than just
 * "a folder"; a plain file's count is always 0.
 *
 * @return array<int, array{name:string, size:int, dir:bool, count:int, link:bool}>|null
 */
function staxx_stack_extras(string $rel, string &$error): ?array {
  $error = '';
  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return null; }
  $dir = @realpath(staxx_stack_dir($rel));
  if ($dir === false || !is_dir($dir)) {
    $error = 'There is no stack called "'.$rel.'".';
    return null;
  }

  // Derived from what is actually paired to this stack, not a flat list of
  // every name compose could ever read — otherwise a folder holding
  // compose.yaml plus an unrelated docker-compose.override.yml would treat
  // that unrelated file as though it belonged to the stack too.
  $composeNames = array_map('basename', staxx_compose_files(staxx_find_compose_file($dir)));

  $out = [];
  foreach ((array)@scandir($dir) as $name) {
    if ($name === '.' || $name === '..') continue;
    if (in_array($name, $composeNames, true)) continue;

    $path  = $dir.'/'.$name;
    $link  = is_link($path);
    $isDir = !$link && is_dir($path);
    $count = $isDir ? max(0, count((array)@scandir($path)) - 2) : 0;
    $st    = @lstat($path) ?: [];

    $out[] = [
      'name'  => $name,
      'size'  => $isDir ? 0 : (int)($st['size'] ?? 0),
      'dir'   => $isDir,
      'count' => $count,
      'link'  => $link,
    ];
  }

  usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
  return $out;
}

/**
 * Delete a stack's directory.
 *
 * Unconfirmed, the guard is what it has always been: a stack folder is
 * allowed to hold a compose file and .env files, and anything else means the
 * user put something there we do not understand — refuse and say what is in
 * the way, rather than delete half of it and then complain.
 *
 * Confirmed, that guard is skipped and the whole tree is removed by
 * staxx_rmtree() below. The function itself never asks; the list of what
 * is about to be destroyed has to reach the user BEFORE anything is removed,
 * and a function that both asks and acts cannot do that — see the "delete"
 * case in action.php, which is what shows the list and sets $confirmed.
 *
 * The containers are stopped first either way. Deleting the file while the
 * stack is up would leave containers running that nothing on this page can
 * reach.
 */
function staxx_delete_stack(string $name, string &$error, bool $confirmed = false): bool {
  $error = '';
  if (!staxx_valid_path($name)) { $error = 'Invalid stack name.'; return false; }

  $dir = staxx_stack_dir($name);
  if (!is_dir($dir)) { $error = 'No such stack.'; return false; }

  // A locked stack's containers, if anything answers to its name, belong to
  // whatever it was imported from — tearing them down is the exact accident
  // the review lock exists to prevent, so this skips straight to removing
  // the folder for one, below.
  $locked = staxx_review_file($dir) !== '';

  // A handover has an old container set aside under another name, waiting for
  // an answer that only this stack's row can give. Delete the stack now and
  // that container is stranded with nothing left to say what it was called or
  // how to put it back — so the answer has to come first.
  if (staxx_handover_file($dir) !== '') {
    $error = 'This stack has just taken over from another container, and that '
           . 'container is set aside waiting for you to say whether the new one '
           . 'works. Answer that first — nothing can be deleted until it is '
           . 'either cleared away or put back.';
    return false;
  }

  // Derived from the stack's own pair, not a flat list of every name compose
  // could ever read — see the same reasoning in staxx_stack_extras(). An
  // unrelated override-shaped file sitting in the folder must be blocked
  // like anything else the plugin does not recognise, not auto-removed.
  $file  = staxx_find_compose_file($dir);
  $files = staxx_compose_files($file);
  $composeNames = array_map('basename', $files);

  $remove = [];
  $blocked = [];
  foreach ((array)@scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $isCompose = in_array($entry, $composeNames, true);
    $isEnv     = $entry === '.env' || str_starts_with($entry, '.env.');
    // The review-lock file is ours too — StaXX writes it on import — so it
    // must not fall into $blocked and demand a by-hand removal, which would
    // refuse to delete every imported stack that has not yet been reviewed.
    // The same goes for the copy held aside during a handover, which is that
    // same file under a leading dot.
    $isReview  = strcasecmp($entry, STAXX_REVIEW_FILE) === 0
              || strcasecmp($entry, '.'.STAXX_REVIEW_FILE) === 0;
    if (is_file($dir.'/'.$entry) && ($isCompose || $isEnv || $isReview)) $remove[] = $entry;
    else $blocked[] = $entry;
  }

  if ($blocked && !$confirmed) {
    $error = 'Nothing was deleted. This folder also contains: '
           . implode(', ', array_slice($blocked, 0, 6))
           . (count($blocked) > 6 ? ', …' : '')
           . '. Remove it by hand if you are sure.';
    return false;
  }

  $cmd  = staxx_compose_cmd();
  if (!$locked && $file !== '' && $cmd !== '' && staxx_docker_running()) {
    $code = 1;
    $out  = staxx_sh(
      'cd '.escapeshellarg($dir).' && '.$cmd.' '.staxx_compose_file_args($files).' down 2>&1',
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

  if ($confirmed) {
    $real = @realpath($dir);
    if ($real === false || !staxx_rmtree($real, $real)) {
      $error = 'Could not remove the folder '.$dir;
      return false;
    }
    return true;
  }

  foreach ($remove as $entry) @unlink($dir.'/'.$entry);

  if (!@rmdir($dir)) {
    $error = 'Removed the files, but could not remove the folder '.$dir;
    return false;
  }
  return true;
}

/**
 * Remove a directory tree. The most destructive code in the plugin, so every
 * step is guarded rather than trusted.
 *
 * $root is fixed for the whole walk — it is always the top of the tree being
 * deleted, never the current recursion's own directory — and every path
 * visited must resolve inside it. That is what stops a path from walking
 * this out of the stack folder.
 *
 * A link is dealt with BEFORE anything resolves the path, and is unlinked
 * rather than entered. Two separate reasons, both learned the hard way:
 *
 * - is_dir() follows a symlink, so testing that first would walk straight
 *   through a link inside the stack folder pointing at, say, /mnt/user, and
 *   delete a real share.
 * - realpath() follows one too, and a link's target is outside $root by
 *   definition — so resolving first turned "remove this link" into "refuse,
 *   that is outside the tree", and one link anywhere made the whole delete
 *   fail with nothing removed.
 *
 * Nothing below the link check can be reached by a link, which is also what
 * makes the containment test sound: every path realpath() sees here is a real
 * directory or file whose ancestors have each already been checked.
 */
function staxx_rmtree(string $path, string $root): bool {
  if (is_link($path)) return @unlink($path);

  $real = @realpath($path);
  if ($real === false) return false;
  if ($real !== $root && strpos($real, $root.'/') !== 0) return false;

  if (is_dir($real)) {
    foreach ((array)@scandir($real) as $entry) {
      if ($entry === '.' || $entry === '..') continue;
      if (!staxx_rmtree($real.'/'.$entry, $root)) return false;
    }
    return @rmdir($real);
  }

  return @unlink($real);
}

/* ----------------------------------------------------------- review lock --
 *
 * An imported stack (phase 2 of the importer) can share its identity with
 * containers somebody else already runs, so it is held "locked pending
 * review" until a human says otherwise: every run verb refused, and delete
 * removes only the folder rather than tearing down containers that might not
 * even be ours. The lock is a plain file in the stack's own folder rather
 * than an entry in settings, so it survives a rename or a folder move for
 * free and doubles as the note explaining the import to whoever opens it.
 */

// The review-lock file's name, sitting beside the compose file it guards.
define('STAXX_REVIEW_FILE', 'NEEDS-REVIEW.md');

/**
 * The review-lock file actually present in $dir, by its real name on disk, or
 * '' when there is none.
 *
 * Matched case-insensitively and the real name returned: the default stack
 * root is the flash drive, which is vfat and does not distinguish case, while
 * an appdata root does. A caller that goes on to delete this file needs the
 * name the filesystem actually holds, not the one we would have written.
 */
function staxx_review_file(string $dir): string {
  foreach ((array)@scandir($dir) as $entry) {
    if (strcasecmp($entry, STAXX_REVIEW_FILE) === 0 && is_file($dir.'/'.$entry)) {
      return $entry;
    }
  }
  return '';
}

/**
 * Is this stack still waiting to be reviewed after an import?
 *
 * Fails safe: an invalid path never resolves to a directory scandir() can
 * read, so this returns false rather than throwing — there is no path where
 * a locked stack quietly reads as unlocked, but there also isn't one where a
 * bad name reads as locked.
 */
function staxx_review_locked(string $rel): bool {
  if (!staxx_valid_path($rel)) return false;
  return staxx_review_file(staxx_stack_dir($rel)) !== '';
}

/* ------------------------------------------------------------ handover ----
 *
 * An imported stack can ask for a container name an existing, hand-made
 * Unraid container already holds — that is exactly what the review lock is
 * there to catch. The handover is the way through it: switch the old
 * container off, rename it aside, bring the stack up under the original
 * name, and wait for a human to say whether it actually worked before the
 * old one is thrown away. See PLAN_42.
 *
 * Every stage is a rename, so every stage has an exact opposite — that is
 * what makes the undo real rather than best-effort. Nothing here ever
 * invents a name: the set-aside name is worked out on the server from a name
 * Docker already accepts, so there is no free text anywhere in what reaches
 * the shell.
 */

// The handover's own note, sitting beside the compose file the same way
// STAXX_REVIEW_FILE does. Present means a handover is waiting to be
// confirmed.
define('STAXX_HANDOVER_FILE', 'HANDOVER.md');

/**
 * Every container Docker knows about, by name — including ones with no
 * compose label at all. staxx_container_index() deliberately drops those,
 * which is exactly what a template's own container has, so this is a
 * separate read. (Import.php has a near-identical one; a later pass can
 * dedupe rather than this file reaching into that one.)
 *
 * @return array<string, array{running:bool, project:string}>
 */
function staxx_docker_container_names(): array {
  static $byName = null;
  if ($byName !== null) return $byName;

  $byName = [];
  if (!staxx_docker_running()) return $byName;

  $fmt = '{{.Names}}\t{{.State}}\t{{.Label "com.docker.compose.project"}}\tend';
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
  );

  foreach (explode("\n", $out) as $line) {
    if (trim($line) === '') continue;
    $c = explode("\t", $line);
    if (count($c) < 3 || $c[0] === '') continue;
    $byName[$c[0]] = ['running' => $c[1] === 'running', 'project' => $c[2]];
  }
  return $byName;
}

/**
 * Which of this stack's own container names something on this server
 * actually answers to today. A service with no container_name cannot clash —
 * compose names it itself — so it is simply absent here, and Docker is never
 * even asked when nothing in the file could clash with anything.
 *
 * $containers is staxx_docker_container_names()'s shape, injectable so the
 * "nothing declared" path — and the matching logic once something is — can
 * be proven with no Docker in the room; every real caller leaves it at null
 * and gets the live list.
 *
 * @param array<string,array{running:bool,project:string}>|null $containers
 * @return array<int, array{service:string, name:string, running:bool}>
 */
function staxx_handover_targets(string $rel, ?array $containers = null): array {
  if (!staxx_valid_path($rel)) return [];
  $file = staxx_find_compose_file(staxx_stack_dir($rel));
  if ($file === '') return [];

  $wanted = [];
  foreach (staxx_compose_meta($file)['services'] as $service => $info) {
    $name = trim((string)($info['container_name'] ?? ''));
    if ($name !== '') $wanted[$service] = $name;
  }
  if (!$wanted) return [];

  if ($containers === null) $containers = staxx_docker_container_names();

  $targets = [];
  foreach ($wanted as $service => $name) {
    if (!isset($containers[$name])) continue;
    $targets[] = [
      'service' => $service,
      'name'    => $name,
      'running' => (bool)($containers[$name]['running'] ?? false),
    ];
  }
  return $targets;
}

/**
 * The name the old container is set aside under — worked out here, never
 * sent from the browser, so there is no free text anywhere in what reaches
 * the shell. Appending a fixed, safe suffix to a name Docker already
 * accepted keeps the result inside Docker's own naming rule automatically;
 * nothing here needs to re-validate the character set.
 *
 * $taken is every name already in use — staxx_docker_container_names()'s
 * keys — passed in rather than fetched here so the collision walk to "-2",
 * "-3" and so on can be tested with no Docker in the room.
 *
 * @param string[] $taken
 */
function staxx_handover_setaside_name(string $original, array $taken): string {
  $candidate = $original.'-before-staxx';
  if (!in_array($candidate, $taken, true)) return $candidate;

  for ($n = 2; ; $n++) {
    $try = $candidate.'-'.$n;
    if (!in_array($try, $taken, true)) return $try;
  }
}

/** The handover state file actually present in $dir, by its real name, or ''. */
function staxx_handover_file(string $dir): string {
  foreach ((array)@scandir($dir) as $entry) {
    if (strcasecmp($entry, STAXX_HANDOVER_FILE) === 0 && is_file($dir.'/'.$entry)) {
      return $entry;
    }
  }
  return '';
}

/** Is a handover on this stack waiting to be confirmed right now? */
function staxx_handover_active(string $rel): bool {
  if (!staxx_valid_path($rel)) return false;
  return staxx_handover_file(staxx_stack_dir($rel)) !== '';
}

/**
 * Write the handover state file: prose for whoever opens it, then a short
 * list of Key-N: value lines a tiny reader parses back — the same trick the
 * review note uses. One target's original name, the name it was set aside
 * under, and whether it had been running form a numbered triplet, so more
 * than one clashing service is represented as plainly as one.
 *
 * @param array<int,array{original:string,setaside:string,wasRunning:bool}> $targets
 */
function staxx_handover_write(string $dir, array $targets, string $when): bool {
  $names = implode(' and ', array_map(fn($t) => '"'.$t['original'].'"', $targets));

  $body = "# Handover in progress\n\n"
        . "This stack is now running in place of $names. The old container has "
        . "been switched off and set aside under a new name — nothing has been "
        . "deleted.\n\n"
        . "Check that the app works, then answer the question on the stack's "
        . "row:\n\n"
        . "- **It works** — the old container is cleared away for good.\n"
        . "- **It does not** — everything goes back exactly as it was, running "
        . "again, within seconds.\n\n---\n";

  foreach ($targets as $i => $t) {
    $n = $i + 1;
    $body .= "Original-$n: {$t['original']}\n";
    $body .= "SetAside-$n: {$t['setaside']}\n";
    $body .= 'WasRunning-'.$n.': '.($t['wasRunning'] ? 'yes' : 'no')."\n";
  }
  $body .= "When: $when\n";

  return @file_put_contents($dir.'/'.STAXX_HANDOVER_FILE, $body) !== false;
}

/**
 * Read the handover state file back into a plain array, or null if there is
 * none. One regex over the lines is enough: every field line is a bare word
 * immediately followed by ":", which none of the prose above it ever is.
 *
 * @return array{targets:array<int,array{original:string,setaside:string,wasRunning:bool}>, when:string}|null
 */
function staxx_handover_read(string $dir): ?array {
  $name = staxx_handover_file($dir);
  if ($name === '') return null;

  $fields = [];
  foreach (explode("\n", (string)@file_get_contents($dir.'/'.$name)) as $line) {
    if (preg_match('/^([A-Za-z]+)(?:-(\d+))?:\s*(.*)$/', trim($line), $m)) {
      $idx = $m[2] !== '' ? (int)$m[2] : 0;
      $fields[$m[1]][$idx] = trim($m[3]);
    }
  }

  $originals = $fields['Original'] ?? [];
  ksort($originals);

  $targets = [];
  foreach ($originals as $idx => $original) {
    $targets[] = [
      'original'   => $original,
      'setaside'   => $fields['SetAside'][$idx] ?? '',
      'wasRunning' => ($fields['WasRunning'][$idx] ?? '') === 'yes',
    ];
  }

  return ['targets' => $targets, 'when' => $fields['When'][0] ?? ''];
}

/**
 * Build (but do not run) the shell script one handover executes.
 *
 * Kept apart from staxx_start_handover() so its exact text can be asserted
 * on with no Docker anywhere near the test — see tests/server/handover.php.
 * Every name goes through escapeshellarg(), including inside undo() and
 * fail(), on the assumption that whatever reaches here might be hostile even
 * though every real caller only ever hands it a name Docker itself reported.
 *
 * @param string[] $files main compose file, plus its override if it has one —
 *                         see staxx_compose_files()
 * @param array<int,array{original:string,setaside:string,wasRunning:bool}> $targets
 */
function staxx_handover_script(
  string $composeCmd, array $files, string $dir, array $targets,
  string $heldReviewPath, string $reviewPath, string $stateFilePath
): string {
  $docker    = escapeshellarg(staxx_docker_bin());
  $fileArgs  = staxx_compose_file_args($files);

  // Every stage below is a rename, so undo is simply every stage reversed —
  // it attempts every reversal regardless of how far the run got, and
  // ignores anything that has nothing to reverse. That is what lets it run
  // safely no matter which step actually failed.
  $undo = ['undo() {'];
  // The exact opposite of the `up -d` below, and it has to come FIRST. A start
  // that fails partway can still have created some of this stack's containers,
  // and those hold the ports and the fixed address the old container needs —
  // so renaming it back and starting it without clearing them first fails on
  // whichever of ours got there. `down` is a no-op when nothing came up.
  $undo[] = '  cd '.escapeshellarg($dir).' 2>/dev/null && '
          . $composeCmd.' '.$fileArgs.' down 2>/dev/null || true';
  foreach ($targets as $t) {
    $orig  = escapeshellarg($t['original']);
    $aside = escapeshellarg($t['setaside']);
    $undo[] = '  '.$docker.' rename '.$aside.' '.$orig.' 2>/dev/null || true';
    if ($t['wasRunning']) {
      $undo[] = '  '.$docker.' start '.$orig.' 2>/dev/null || true';
    }
  }
  $undo[] = '  mv '.escapeshellarg($heldReviewPath).' '.escapeshellarg($reviewPath).' 2>/dev/null || true';
  $undo[] = '  rm -f '.escapeshellarg($stateFilePath).' 2>/dev/null || true';
  $undo[] = '  echo "Nothing was changed — the stack is back exactly as it was."';
  $undo[] = '}';

  // Every step's failure goes through here: say what broke, undo everything,
  // then still print the sentinel so the page's poll ends instead of hanging
  // on a job that looks like it is still running.
  $fail = [
    'fail() {',
    '  echo "$1"',
    '  undo',
    '  echo "'.STAXX_JOB_END.' 1"',
    '  exit 1',
    '}',
  ];

  // Stop every target first, then rename every target aside, then bring the
  // stack up — see PLAN_42's "the sequence" for why this order, not
  // interleaved per-target, is what makes a partial failure's undo simple.
  $steps = [];
  foreach ($targets as $t) {
    $orig = escapeshellarg($t['original']);
    $steps[] = $docker.' stop '.$orig.' 2>&1 || fail '
             . escapeshellarg('Could not switch off '.$t['original'].'.');
  }
  foreach ($targets as $t) {
    $orig  = escapeshellarg($t['original']);
    $aside = escapeshellarg($t['setaside']);
    $steps[] = $docker.' rename '.$orig.' '.$aside.' 2>&1 || fail '
             . escapeshellarg('Could not set '.$t['original'].' aside.');
  }
  $steps[] = 'cd '.escapeshellarg($dir).' 2>&1 || fail '
           . escapeshellarg('Could not reach the stack folder.');
  $steps[] = $composeCmd.' '.$fileArgs.' up -d --remove-orphans 2>&1 || fail '
           . escapeshellarg('The stack could not be started.');
  $steps[] = 'rm -f '.escapeshellarg($heldReviewPath).' 2>&1';
  $steps[] = 'echo "Handed over. Check that the app works, then answer the question on the stack row." 2>&1';
  $steps[] = 'echo "'.STAXX_JOB_END.' 0"';

  return implode("\n", $undo)."\n\n".implode("\n", $fail)."\n\n".implode("\n", $steps)."\n";
}

/**
 * Start a handover: switch off, rename aside and start the new stack in its
 * place. Returns a job id for the existing job poller, or '' with a sentence
 * explaining the refusal — every one of these is checked before anything is
 * written, so a refusal never leaves so much as the state file behind.
 */
function staxx_start_handover(string $rel, string &$error): string {
  $error = '';

  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return ''; }

  $dir = staxx_stack_dir($rel);
  if (!is_dir($dir)) { $error = 'There is no stack called "'.$rel.'".'; return ''; }

  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

  // Checked before the lock itself: starting a handover moves the review
  // note aside rather than deleting it, so a stack mid-handover no longer
  // carries the exact review-lock name — staxx_review_locked() would read it
  // as "never was locked" and give a confusing answer. The state file is
  // what actually distinguishes the two, so it is asked first.
  if (staxx_handover_active($rel)) {
    $error = 'A handover for this stack is already waiting to be confirmed. '
           . 'Answer whether it worked before starting another.';
    return '';
  }

  // The handover is not an exception carved into the review lock — it
  // REQUIRES one. It is the only door through it, and refuses everything
  // else. See PLAN_42's "no exception to the lock is needed".
  if (!staxx_review_locked($rel)) {
    $error = 'This stack is not awaiting review, so there is nothing for a handover to take over.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $targets = staxx_handover_targets($rel);
  if (!$targets) {
    $error = 'Nothing on this server answers to a container name this stack asks for. '
           . 'Start the stack normally instead.';
    return '';
  }

  $containers = staxx_docker_container_names();
  foreach ($targets as $t) {
    $project = $containers[$t['name']]['project'] ?? '';
    if ($project !== '') {
      $error = 'The container "'.$t['name'].'" already belongs to another compose '
             . 'project ("'.$project.'"), so this is not a plain template container '
             . 'and a handover would be guessing. Sort that out by hand first.';
      return '';
    }
  }

  $reviewName = staxx_review_file($dir);
  if ($reviewName === '') {
    // Cannot happen given the lock check above, but there is no safe way to
    // hold a note that has already gone missing.
    $error = 'The review note has gone missing, so this cannot be started safely.';
    return '';
  }
  $heldPath   = $dir.'/.'.$reviewName;
  $reviewPath = $dir.'/'.$reviewName;

  $setasides = [];
  foreach ($targets as $t) {
    $setasides[] = [
      'original'   => $t['name'],
      'setaside'   => staxx_handover_setaside_name($t['name'], array_keys($containers)),
      'wasRunning' => $t['running'],
    ];
  }

  // Nothing has been written up to this point — every refusal above had to
  // come first, not just happen to.
  if (!staxx_handover_write($dir, $setasides, gmdate('c'))) {
    $error = 'Could not write the handover note, so nothing was started.';
    return '';
  }

  if (!@rename($reviewPath, $heldPath)) {
    @unlink($dir.'/'.STAXX_HANDOVER_FILE);
    $error = 'Could not set the review note aside, so nothing was started.';
    return '';
  }

  if (!is_dir(STAXX_JOB_DIR) && !@mkdir(STAXX_JOB_DIR, 0755, true)) {
    // Put both files back — nothing should look started when nothing was.
    @rename($heldPath, $reviewPath);
    @unlink($dir.'/'.STAXX_HANDOVER_FILE);
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $script = staxx_handover_script(
    $cmd, $files, $dir, $setasides, $heldPath, $reviewPath, $dir.'/'.STAXX_HANDOVER_FILE
  );

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  $shownFiles = implode(' ', array_map(fn($f) => '-f '.basename($f), $files));
  $shown = implode(' && ', array_merge(
    array_map(fn($t) => 'docker stop '.$t['original'], $setasides),
    array_map(fn($t) => 'docker rename '.$t['original'].' '.$t['setaside'], $setasides),
    ['compose '.$shownFiles.' up -d --remove-orphans']
  ));
  @file_put_contents($log, '$ '.$shown."\n\n");

  @exec('setsid sh -c '.escapeshellarg($script).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/**
 * Finish a handover once a human has said whether it worked. Also a job id,
 * same machinery as every other run — refuses outright if there is nothing
 * to finish.
 */
function staxx_finish_handover(string $rel, bool $worked, string &$error): string {
  $error = '';

  if (!staxx_valid_path($rel) || !staxx_handover_active($rel)) {
    $error = 'There is no handover in progress for this stack.';
    return '';
  }

  $dir   = staxx_stack_dir($rel);
  $state = staxx_handover_read($dir);
  if ($state === null || !$state['targets']) {
    $error = 'The handover record could not be read, so nothing was changed.';
    return '';
  }

  $docker    = escapeshellarg(staxx_docker_bin());
  $stateFile = escapeshellarg($dir.'/'.STAXX_HANDOVER_FILE);

  if ($worked) {
    $steps = [];
    foreach ($state['targets'] as $t) {
      $steps[] = $docker.' rm -f '.escapeshellarg($t['setaside']).' 2>&1';
    }
    $steps[] = 'rm -f '.$stateFile.' 2>&1';
    $steps[] = 'echo "The old container has been cleared away." 2>&1';

    $shown = implode(' && ', array_map(fn($t) => 'docker rm -f '.$t['setaside'], $state['targets']));
  } else {
    // The moment the answer is "no", the stack must stop being runnable — and
    // that cannot wait for a background job, so the note goes back
    // synchronously, in PHP, before the job that undoes the rest is even
    // launched.
    @file_put_contents($dir.'/'.STAXX_REVIEW_FILE,
      "# Imported, handed over, and put back\n\n"
      . "This stack was started in place of its original container and reported "
      . "not to work, so everything was put back as it was: the original "
      . "container has its own name again and is running, and this stack is "
      . "stopped and locked.\n\n"
      . "Nothing was lost. Look at the compose file, change whatever was wrong, "
      . "then choose \"Take over and start\" from this stack's menu to try "
      . "again.\n"
    );

    $composeCmd = staxx_compose_cmd();
    $file       = staxx_find_compose_file($dir);
    $files      = staxx_compose_files($file);

    $steps = [];
    if ($composeCmd !== '' && $file !== '') {
      $steps[] = 'cd '.escapeshellarg($dir).' 2>&1';
      $steps[] = $composeCmd.' '.staxx_compose_file_args($files).' down 2>&1';
    }
    foreach ($state['targets'] as $t) {
      $steps[] = $docker.' rename '.escapeshellarg($t['setaside']).' '.escapeshellarg($t['original']).' 2>&1';
      if ($t['wasRunning']) {
        $steps[] = $docker.' start '.escapeshellarg($t['original']).' 2>&1';
      }
    }
    $steps[] = 'rm -f '.$stateFile.' 2>&1';
    $steps[] = 'echo "Put back exactly as it was." 2>&1';

    $shown = 'compose down && '.implode(' && ', array_map(
      fn($t) => 'docker rename '.$t['setaside'].' '.$t['original'], $state['targets']
    ));
  }

  $steps[] = 'echo "'.STAXX_JOB_END.' $?"';
  $script  = implode("\n", $steps)."\n";

  if (!is_dir(STAXX_JOB_DIR) && !@mkdir(STAXX_JOB_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';
  @file_put_contents($log, '$ '.$shown."\n\n");
  @exec('setsid sh -c '.escapeshellarg($script).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/* ------------------------------------------------------------ takeover ----
 *
 * A Compose Manager import needs none of the handover's stopping, renaming
 * and undo state, because it deliberately carries the SAME project name the
 * running containers already have. Bringing the stack up is enough — Docker
 * finds those containers under its own label and rebuilds them in place. See
 * PLAN_46 Part D.
 */

/**
 * The containers Docker already runs under this stack's own project name —
 * what a takeover would rebuild. Read off staxx_container_index(), which
 * already costs one `docker ps -a` per request; nothing here shells out
 * again.
 *
 * @return array<int, array{name:string, running:bool}>
 */
function staxx_project_containers(string $rel): array {
  if (!staxx_valid_path($rel)) return [];

  $project = staxx_project_name(staxx_path_leaf($rel));
  if ($project === '') return [];

  $rows = staxx_container_index()['byProject'][$project] ?? [];
  // Lower-cased on the way past, as every other reader of this index does:
  // the state is whatever `docker ps` printed, and nothing here is worth
  // betting on its capitalisation.
  return array_map(fn($r) => [
    'name'    => $r['name'],
    'running' => strtolower($r['state']) === 'running',
  ], $rows);
}

/**
 * Start a takeover: bring the stack up so compose finds the containers a
 * Compose Manager project already runs under this same project name, and
 * rebuilds them in place. Returns a job id, or '' with a sentence explaining
 * the refusal.
 *
 * No renames, no set-aside containers, no state file and no follow-up
 * question — unlike a handover, nothing here is ever moved, so there is
 * nothing to undo and nothing for a human to confirm afterwards.
 */
function staxx_start_takeover(string $rel, string &$error): string {
  $error = '';

  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return ''; }

  $dir = staxx_stack_dir($rel);
  if (!is_dir($dir)) { $error = 'There is no stack called "'.$rel.'".'; return ''; }

  // A handover already under way has containers renamed aside waiting on an
  // answer. Bringing this stack up on top of that would create its own
  // containers alongside them and leave the handover with nothing sane to
  // roll back to, so it is refused rather than raced.
  if (staxx_handover_active($rel)) {
    $error = 'A handover is already under way on this stack. Answer whether that one '
           . 'worked before starting anything else here.';
    return '';
  }

  // Not an exception carved into the review lock — it REQUIRES one, exactly
  // as a handover does. It is the only door through it.
  if (!staxx_review_locked($rel)) {
    $error = 'This stack is not awaiting review, so there is nothing for a takeover to start.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  if (!staxx_project_containers($rel)) {
    $error = 'Nothing on this server is running under this stack\'s project name, '
           . 'so there is nothing to take over. Start the stack normally instead.';
    return '';
  }

  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

  $reviewName = staxx_review_file($dir);
  if ($reviewName === '') {
    // Cannot happen given the lock check above, but there is no safe way to
    // hold a note that has already gone missing.
    $error = 'The review note has gone missing, so this cannot be started safely.';
    return '';
  }
  $heldPath   = $dir.'/.'.$reviewName;
  $reviewPath = $dir.'/'.$reviewName;

  // Held aside rather than deleted up front, the same as a handover, so the
  // stack is never briefly unlocked while the job runs.
  if (!@rename($reviewPath, $heldPath)) {
    $error = 'Could not set the review note aside, so nothing was started.';
    return '';
  }

  if (!is_dir(STAXX_JOB_DIR) && !@mkdir(STAXX_JOB_DIR, 0755, true)) {
    @rename($heldPath, $reviewPath);
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  // Built the same way staxx_start_job() builds its one 'up' step, so this is
  // not a new verb with its own prefix — just the one line the job runner
  // would already produce for `up`, run through the same detached machinery.
  $fileArgs = staxx_compose_file_args($files);
  $step     = $cmd.' '.$fileArgs.' up -d --remove-orphans 2>&1';

  $shownFiles = implode(' ', array_map(fn($f) => '-f '.basename($f), $files));
  @file_put_contents($log, '$ compose '.$shownFiles." up -d --remove-orphans\n\n");

  // $? is captured into $ec straight after the one real step, before the
  // rollback's own commands get a chance to overwrite it — the same reason
  // staxx_start_job() puts its STAXX_JOB_END check right after the chain.
  $script = 'cd '.escapeshellarg($dir).' && '.$step.'; ec=$?; '
          . 'if [ "$ec" -eq 0 ]; then rm -f '.escapeshellarg($heldPath).' 2>&1; '
          . 'else mv '.escapeshellarg($heldPath).' '.escapeshellarg($reviewPath).' 2>/dev/null; fi; '
          . 'echo "'.STAXX_JOB_END.' $ec"';

  @exec('setsid sh -c '.escapeshellarg($script).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/* ------------------------------------------------------- companion files --
 *
 * A stack folder may hold more than the compose file now: a .env, a
 * certificate, a config snippet the compose file reads with `configs:` or a
 * bind mount. These are "companion files" — everything below reads, writes,
 * renames or deletes one, always confined to a single stack's own folder.
 *
 * Every helper resolves its target through staxx_stack_file(), which
 * validates the stack's path AND the filename before touching disk, so none
 * of the checks below can drift out of step between one helper and another.
 */

/**
 * Resolve a companion file's path inside a stack, validating both halves.
 */
function staxx_stack_file(string $rel, string $file, string &$error): string {
  $error = '';
  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return ''; }
  if (!staxx_valid_filename($file)) {
    $error = 'File names may contain letters, numbers, dots, dashes and underscores, '
           . 'must be 63 characters or fewer, and may not be a compose file.';
    return '';
  }

  $dir = @realpath(staxx_stack_dir($rel));
  if ($dir === false || !is_dir($dir)) {
    $error = 'There is no stack called "'.$rel.'".';
    return '';
  }

  // Safe to concatenate: $file has just been through staxx_valid_filename(),
  // which forbids both a slash and "..", so it cannot name anything but a
  // direct child of $dir — and $dir has itself just been resolved with
  // realpath(). Neither half is trusted on its own; both checks have to pass.
  return $dir.'/'.$file;
}

/**
 * Everything in a stack's folder, compose file first and then alphabetical.
 *
 * @return array<int, array{name:string, size:int, mtime:int, compose:bool,
 *                           text:bool, dir:bool, link:bool, review:bool}>|null
 */
function staxx_list_files(string $rel, string &$error): ?array {
  $error = '';
  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return null; }
  $dir = @realpath(staxx_stack_dir($rel));
  if ($dir === false || !is_dir($dir)) {
    $error = 'There is no stack called "'.$rel.'".';
    return null;
  }

  $out = [];
  foreach ((array)@scandir($dir) as $name) {
    if ($name === '.' || $name === '..') continue;

    $path  = $dir.'/'.$name;
    $link  = is_link($path);
    $isDir = is_dir($path);
    $st    = @lstat($path) ?: [];

    $out[] = [
      'name'    => $name,
      'size'    => (int)($st['size'] ?? 0),
      'mtime'   => (int)($st['mtime'] ?? 0),
      'compose' => in_array($name, STAXX_COMPOSE_FILENAMES, true),
      // A directory or a link is never offered as editable text — a link is
      // listed but never followed for editing; see staxx_read_file().
      'text'    => (!$isDir && !$link) ? staxx_looks_text($path) : false,
      'dir'     => $isDir,
      'link'    => $link,
      // Matched case-insensitively, same as staxx_review_file() — this is the
      // per-entry flag the sort below uses to put it right after the compose
      // file, so it reads as the first thing worth opening.
      'review'  => strcasecmp($name, STAXX_REVIEW_FILE) === 0,
    ];
  }

  usort($out, function ($a, $b) {
    if ($a['compose'] !== $b['compose']) return $a['compose'] ? -1 : 1;
    // The review note sorts right after the compose file rather than
    // alphabetically among the companions, so it is the first thing seen —
    // it explains an import that nothing else in the folder does.
    if ($a['review']  !== $b['review'])  return $a['review']  ? -1 : 1;
    return strnatcasecmp($a['name'], $b['name']);
  });
  return $out;
}

/**
 * Does this look like a text file? The same test git uses: no NUL byte in
 * the first 8 KB. A UTF-16 file will be called binary, which is the safe way
 * round to get this wrong.
 */
function staxx_looks_text(string $path): bool {
  $fh = @fopen($path, 'rb');
  if ($fh === false) return false;
  $chunk = fread($fh, 8192);
  fclose($fh);
  return $chunk !== false && strpos($chunk, "\0") === false;
}

/**
 * Read one companion file for the editor.
 *
 * @return array{text?:string, b64?:string, binary:bool}|null
 */
function staxx_read_file(string $rel, string $file, string &$error): ?array {
  $path = staxx_stack_file($rel, $file, $error);
  if ($path === '') return null;

  if (is_link($path)) {
    $error = 'That is a link, not a file. Links are shown but not edited here.';
    return null;
  }
  if (is_dir($path)) {
    $error = '"'.$file.'" is a folder, not a file.';
    return null;
  }
  if (!is_file($path)) {
    $error = 'There is no file called "'.$file.'" in this stack.';
    return null;
  }

  $size = @filesize($path);
  if ($size !== false && $size > STAXX_FILE_MAX) {
    // ceil(), not round(): a file one byte over the cap rounds back down to
    // the cap itself, and "is 256 KiB, over the 256 KiB limit" reads as a
    // mistake rather than a rule.
    $error = '"'.$file.'" is '.ceil($size / 1024).' KiB, over the '
           . round(STAXX_FILE_MAX / 1024).' KiB limit for editing here.';
    return null;
  }

  $raw = @file_get_contents($path);
  if ($raw === false) { $error = 'Could not read '.$path; return null; }

  if (staxx_looks_text($path)) return ['text' => $raw, 'binary' => false];
  return ['b64' => base64_encode($raw), 'binary' => true];
}

/**
 * Write one companion file, text or binary.
 *
 * Written to a temp file in the same directory and rename()'d into place, so
 * a reader never sees half a file — the same reasoning staxx_save_stack()
 * uses for the compose file itself.
 *
 * The bytes go down exactly as sent, text or binary alike. A file's own line
 * endings are the editor's to keep — a browser textarea hands its value back
 * as LF whatever went into it, so the page puts CRLF back for a file that had
 * it, and normalising here would undo that and reformat the file instead.
 * $isText no longer changes what is written; it stays because every caller
 * knows which kind it is holding and the reader reports the same distinction.
 */
function staxx_write_file(string $rel, string $file, string $body, bool $isText, string &$error): bool {
  $path = staxx_stack_file($rel, $file, $error);
  if ($path === '') return false;

  if (is_link($path)) {
    $error = 'That is a link, not a file. Links are shown but not edited here.';
    return false;
  }
  if (is_dir($path)) {
    $error = '"'.$file.'" is a folder, not a file.';
    return false;
  }

  if (strlen($body) > STAXX_FILE_MAX) {
    $error = 'That file is over the '.round(STAXX_FILE_MAX / 1024).' KiB limit for a companion file.';
    return false;
  }

  $tmp = $path.'.staxx-tmp';
  if (@file_put_contents($tmp, $body) === false) {
    $error = 'Could not write '.$tmp;
    return false;
  }
  @chmod($tmp, 0644);
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    $error = 'Could not save "'.$file.'" — the temporary file could not be put in place.';
    return false;
  }
  return true;
}

/**
 * Delete one companion file. A link is unlinked, never followed; a directory
 * is refused — the caller means the stack's own delete for that.
 */
function staxx_delete_file(string $rel, string $file, string &$error): bool {
  $path = staxx_stack_file($rel, $file, $error);
  if ($path === '') return false;

  if (is_link($path)) {
    if (!@unlink($path)) { $error = 'Could not delete "'.$file.'".'; return false; }
    return true;
  }
  if (is_dir($path)) {
    $error = '"'.$file.'" is a folder. Delete the stack itself if you want the folder gone.';
    return false;
  }
  if (!is_file($path)) {
    $error = 'There is no file called "'.$file.'" in this stack.';
    return false;
  }
  if (!@unlink($path)) {
    $error = 'Could not delete "'.$file.'".';
    return false;
  }
  return true;
}

/** Rename one companion file within its own folder. */
function staxx_rename_file(string $rel, string $from, string $to, string &$error): bool {
  $fromPath = staxx_stack_file($rel, $from, $error);
  if ($fromPath === '') return false;
  $toPath = staxx_stack_file($rel, $to, $error);
  if ($toPath === '') return false;

  if (!file_exists($fromPath) && !is_link($fromPath)) {
    $error = 'There is no file called "'.$from.'" in this stack.';
    return false;
  }
  if (file_exists($toPath) || is_link($toPath)) {
    $error = 'There is already a file called "'.$to.'" in this stack.';
    return false;
  }
  if (!@rename($fromPath, $toPath)) {
    $error = 'Could not rename "'.$from.'" to "'.$to.'".';
    return false;
  }
  return true;
}

/**
 * Rename a stack — move its own directory to a new leaf name, without
 * changing which folder (if any) it sits in.
 *
 * The same shape as staxx_folder_assign(): validate, check the target is
 * not already taken, rename() on disk, return the new rel so the caller can
 * re-key itself. The collision check is staxx_folder_taken()'s logic
 * inlined rather than called — Stacks.php sits below Folders.php in the
 * include order and must not depend on it.
 */
function staxx_rename_stack(string $rel, string $newLeaf, ?string &$error = null): string {
  $error   = '';
  $newLeaf = trim($newLeaf);

  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return ''; }
  if (!staxx_valid_name($newLeaf)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return '';
  }

  $from = staxx_stack_dir($rel);
  if (!is_dir($from)) {
    $error = 'That stack is no longer there. Refresh the page and try again.';
    return '';
  }

  $folder = staxx_path_folder($rel);
  $newRel = $folder === '' ? $newLeaf : $folder.'/'.$newLeaf;
  if ($newRel === $rel) return $rel;

  $scan = staxx_scan_stacks();

  // A stack at the top level shares its namespace with folders — "jellyfin"
  // could be either — so only a top-level rename needs to check both. A
  // stack filed in a folder only has to avoid its siblings inside that same
  // folder; it cannot collide with a folder name at all, since its new path
  // sits one level below one.
  if ($folder === '') {
    foreach ($scan['folders'] as $f) {
      if (strcasecmp($f, $newLeaf) === 0) {
        $error = 'There is already a folder called "'.$newLeaf.'".';
        return '';
      }
    }
  }
  foreach ($scan['stacks'] as $s) {
    if ($s['rel'] === $rel) continue;                    // itself
    if ($s['folder'] !== $folder) continue;
    if (strcasecmp($s['leaf'], $newLeaf) === 0) {
      $error = 'There is already a stack called "'.$newLeaf.'"'
             . ($folder === '' ? '.' : ' in "'.$folder.'".');
      return '';
    }
  }

  // The scan above only knows about stacks and folders. A stray empty directory
  // is neither, and rename() onto an empty directory *succeeds* on Linux — so
  // without this the stray would be silently swallowed.
  $to = staxx_stack_root().'/'.$newRel;
  if (file_exists($to)) {
    $error = 'Something called "'.$newLeaf.'" is already there. Pick another name.';
    return '';
  }
  if (!@rename($from, $to)) {
    $error = 'Could not rename the stack on disk. Check the permissions on '.$from.'.';
    return '';
  }
  return $newRel;
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
 * `restart` and `update` below and staxx_start_job(), which is what actually
 * walks that array. Every other verb uses a single string. Four of these
 * pairs are not the obvious mirror of each other:
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
 *   restart   is not `compose restart` on its own. That command stops and
 *             starts the container that already exists, and a container's
 *             settings — its published ports above all — are fixed when it
 *             is built, so an edited compose file has no effect on it
 *             whatsoever. Someone who adds a port, presses Restart and finds
 *             the port missing has been told the change was applied when it
 *             was not. Unraid's own Apply on a template rebuilds the
 *             container, and Restart here means the same thing.
 *
 *             So it is two steps: `up -d` and then `restart`. Compose stamps
 *             each container with a hash of the settings it was built from
 *             and compares it, so `up -d` rebuilds exactly the containers
 *             whose settings changed and returns in milliseconds when none
 *             did — there is nothing for this plugin to track. `restart`
 *             then bounces the rest, which is the half `up -d` will not do
 *             on its own.
 *
 *             Not `up -d --force-recreate`, which would be one step and does
 *             both jobs at once: it rebuilds every container in the stack
 *             whether or not anything about it changed, and rebuilding
 *             throws away a container's logs. Restart is what you press when
 *             something is misbehaving, so destroying the evidence is the
 *             worst possible moment. Measured on a four-container stack:
 *             force-recreate 20.9s, this pair 10.5s when nothing changed.
 *
 *             `up -d` goes first for a second reason. The steps are chained
 *             with `&&`, so a file compose rejects fails there having
 *             disturbed nothing, rather than after the whole stack has
 *             already been bounced. The cost of the pair is that a container
 *             that DID change is started by the rebuild and then bounced
 *             again by the restart — wasted, but only for the ones that
 *             changed, and cheaper than the alternative for all the rest.
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
 *             `update`: at stack scope the two halves are separate buttons,
 *             "Update images" to fetch and Restart to rebuild onto what was
 *             fetched, so a pull can be left to finish on a busy stack
 *             without taking it down as a side effect. See the note beside
 *             that item in stacks.js.
 *
 * `config` has no service form because nothing in the menu ever asks for one
 * — "resolved settings" is a whole-file question.
 */
function staxx_job_verbs(): array {
  return [
    'up'      => ['args' => 'up -d --remove-orphans', 'svc' => 'up -d',             'label' => 'Start'],
    'down'    => ['args' => 'down',                   'svc' => 'stop',              'label' => 'Stop'],
    'restart' => ['args' => ['up -d --remove-orphans', 'restart'],
                  'svc'  => ['up -d', 'restart'],                                    'label' => 'Restart'],
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
function staxx_start_job(string $name, string $verb, string &$error, string $service = ''): string {
  $error = '';

  $verbs = staxx_job_verbs();
  if (!isset($verbs[$verb]))       { $error = 'Unknown action.';     return ''; }
  if (!staxx_valid_path($name)) { $error = 'Invalid stack name.'; return ''; }

  // Checked before anything about the environment, and before the scope
  // split below, so a locked stack is refused for the right reason — and at
  // every scope, whole-stack or single-service — even when compose or docker
  // also happen to be unavailable.
  if (staxx_review_locked($name)) {
    $error = 'This stack was imported and has not been reviewed yet. Open it, read '
           . STAXX_REVIEW_FILE . ', then choose "Mark as reviewed" before starting it.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = staxx_stack_dir($name);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

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
  // Normalise to a list of steps. Most verbs supply a single string, which
  // becomes a one-element list here and behaves exactly as it always has;
  // `restart` and `update` supply their pair of steps already.
  $steps = is_array($args) ? $args : [$args];

  if ($service !== '') {
    // The allowlist for a service name: it must be a key of the services the
    // compose file itself declares, the same shape of guarantee
    // staxx_valid_name() gives for stack names. A regex on the shape of
    // the string would accept a service that simply does not exist in this
    // stack; this is a real membership check against the file.
    $services = staxx_compose_meta($file)['services'];
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

  if (!is_dir(STAXX_JOB_DIR) && !@mkdir(STAXX_JOB_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  // Each step becomes its own `compose -f <file> <step>` invocation, and the
  // chain below is what actually runs — `restart` is `up -d` and `restart`
  // back to back in the same shell command, not two separate jobs. Two things
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
  // code, and the `STAXX_JOB_END $?` line below reports the real failure
  // instead of the exit code of a step that never ran.
  $invocations = array_map(
    fn($step) => $cmd.' '.staxx_compose_file_args($files).' '.$step.' 2>&1',
    $steps
  );
  $chain = implode(' && ', $invocations);

  $inner = 'cd '.escapeshellarg($dir).' && '
         . $chain
         . '; echo "'.STAXX_JOB_END.' $?"';

  // The log's first line is what the output panel shows above the command's
  // own output, so it has to say what actually ran — service name included,
  // every file in play (a two-file stack's override is otherwise invisible
  // here), and every step of the chain for a multi-step verb, not just the
  // stack-scoped $verbs[$verb]['args']. Built from the same $steps that were
  // just turned into $invocations above, so the line shown and the command
  // run can never drift apart — e.g. for `update` on service "demo-cache":
  // `$ compose -f compose.yaml pull 'demo-cache' && compose -f compose.yaml up -d 'demo-cache'`.
  $shownFiles = implode(' ', array_map(fn($f) => '-f '.basename($f), $files));
  $shown = implode(' && ', array_map(fn($step) => 'compose '.$shownFiles.' '.$step, $steps));
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
function staxx_job_log(string $job): array {
  if (!preg_match('/^[0-9a-f]{16}$/', $job)) return ['text' => '', 'done' => true, 'exit' => null];

  $log  = STAXX_JOB_DIR.'/'.$job.'.log';
  $text = is_file($log) ? (string)@file_get_contents($log) : '';

  $done = false;
  $exit = null;
  if (preg_match('/^'.preg_quote(STAXX_JOB_END, '/').' (\d+)\s*$/m', $text, $m)) {
    $done = true;
    $exit = (int)$m[1];
    $text = trim(preg_replace('/^'.preg_quote(STAXX_JOB_END, '/').' \d+\s*$/m', '', $text));
  }

  return ['text' => $text, 'done' => $done, 'exit' => $exit];
}

/** Remove job logs older than an hour, so /tmp does not fill up over time. */
function staxx_prune_jobs(): void {
  if (!is_dir(STAXX_JOB_DIR)) return;
  foreach ((array)@glob(STAXX_JOB_DIR.'/*.log') as $log) {
    if (@filemtime($log) < time() - 3600) @unlink($log);
  }
}
?>
