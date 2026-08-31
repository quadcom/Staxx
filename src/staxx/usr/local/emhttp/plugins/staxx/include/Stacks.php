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
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
require_once '/usr/local/emhttp/plugins/staxx/include/BootCopy.php';
// The bundle importer writes a stack's own picture and checks it is really a
// picture, both of which live here. Named rather than left to whichever page
// happened to pull Icons.php in first, so a server suite that requires only
// this file still finds them.
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';

if (defined('STAXX_JOB_DIR')) return;

// Job logs live in RAM, not on the flash drive. They are noisy, they are
// worthless after a reboot, and the flash drive has a finite number of writes.
define('STAXX_JOB_DIR', '/tmp/staxx/jobs');

// Written to a job's log once the command finishes, followed by its exit code.
// Deliberately unlikely to appear in compose output.
define('STAXX_JOB_END', '###staxx-finished###');

// The most any single poll of a log or exec buffer hands back. Without this a
// first poll against a long-running `pull` (or an exec session nobody has
// read from in a while) returns the whole thing in one reply; a later poll
// just picks up at the new offset, so nothing is lost by capping it.
define('STAXX_LOG_CHUNK_MAX', 256 * 1024);

// Remembered `docker compose config` answers, one file per stack, in /tmp for
// the same reasons as the job logs above. See staxx_compose_meta().
define('STAXX_META_DIR', '/tmp/staxx/meta');

// A log follower's own directory — separate from STAXX_JOB_DIR because a
// follower's lifetime works differently: a job ends on its own and is only
// ever pruned by age, while a follower is meant to keep running and has to be
// reaped on a heartbeat instead. See staxx_log_start().
define('STAXX_LOG_DIR', '/tmp/staxx/logs');

// Same stale figure scripts/stats-collector.sh uses for the same reason: give
// up on a follower once nobody has asked it for output in this many seconds.
define('STAXX_LOG_STALE', 45);

// A `compose logs --follow` file has no size ceiling of its own, and an open
// tab keeps its heartbeat fresh indefinitely — so a chatty container over a
// long weekend could otherwise fill /tmp, which is RAM on Unraid. This is the
// most a follower's log is allowed to grow to before staxx_log_reap() kills
// it outright, heartbeat or not.
define('STAXX_LOG_SIZE_MAX', 64 * 1024 * 1024);

// Cap on what a single "download the whole log" reply carries — the browser
// posts everything in one page load, so this is chosen to sit well under
// PHP's post_max_size the same way STAXX_FILE_MAX does for a companion file.
define('STAXX_LOG_DOWNLOAD_MAX', 2097152); // 2 MiB

// A shell session's own directory — see the "exec" section near the foot of
// this file. Under /tmp for the same reason job logs and followers are:
// worthless after a reboot, and noisy.
define('STAXX_EXEC_DIR', '/tmp/staxx/exec');

// A single write down a session's pipe is a keystroke or a short pasted
// line, never a file. This is what stops the input box being used to
// smuggle something much larger through a pipe meant for typing.
define('STAXX_EXEC_WRITE_MAX', 4096);

// Compose reads whichever of these it finds first, in this order.
const STAXX_COMPOSE_FILENAMES = [
  'compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml',
];

// The hidden per-stack history folder (see Record.php). Never listed, never
// reachable through the companion-file tools — a stack works exactly as it
// does today whether or not this exists.
define('STAXX_RECORD_DIR', '.staxx');

// A companion file's size cap. The browser reads the file itself and posts
// its content as an ordinary form field, so the whole thing sits in one POST
// body — this keeps that comfortably under PHP's post_max_size (8M on the
// test box).
define('STAXX_FILE_MAX', 262144);   // 256 KiB

// The container file manager's own temp folder — a `docker cp` lands here on
// its way in or out. Under /tmp for the same reason job logs and exec
// sessions are: worthless after a reboot, and noisy.
define('STAXX_CFILE_TMP', '/tmp/staxx/cfile');

// Cap on one directory listing inside a container, the same idea as
// staxx_browse_dirs()'s cap on the host-folder picker: a directory with tens
// of thousands of entries gets a partial answer instead of no answer at all.
define('STAXX_CFILE_LIST_MAX', 2000);

// The one placeholder value StaXX ever writes in on someone's behalf — used
// by an exported stack to mark a value it stripped out, and by the export
// screen to know a blank has been left rather than a real one forgotten. One
// literal string, never varied by field, so a plain text search is all it
// takes to find every one of them.
define('STAXX_PLACEHOLDER', 'REPLACE-ME');

// PLAN_76 Phase 5 — the export packer's own caps. The browser posts every
// selected file's content as one JSON array in a single POST body, so this
// keeps the total comfortably under PHP's post_max_size (8M on the test box)
// the same way STAXX_LOG_DOWNLOAD_MAX does for a whole log download.
define('STAXX_EXPORT_MAX', 2097152); // 2 MiB, summed across every file

// Not a size question — a sanity ceiling so a malformed request cannot make
// the packer loop over an unbounded list of tiny entries.
define('STAXX_EXPORT_FILES_MAX', 200);

// PLAN_101 — the bundle importer's own cap on an uploaded ".staxx" file. The
// real server's PHP accepts an 8 MB request body, and the bundle travels
// base64-encoded — a third larger than its raw bytes — while an export can
// only ever hold STAXX_EXPORT_MAX (2 MiB) of content. 4 MiB raw is roomy for
// anything StaXX itself ever made, and still safely inside what PHP will
// accept once encoded.
define('STAXX_BUNDLE_MAX', 4194304); // 4 MiB

/**
 * Make sure a job/log working directory exists and is private — job output
 * can hold a resolved compose config, variables and all, so nobody but this
 * process's owner should be able to read it. The same lesson
 * staxx_browse_mkdir() already carries: mkdir()'s mode is filtered by the
 * process umask, so it has to be set again with chmod() regardless of what
 * mkdir() was given, and regardless of whether this call made the directory
 * or found it already there from an earlier, looser-mode request.
 */
function staxx_private_dir(string $dir): bool {
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  @chmod($dir, 0700);
  return true;
}

/* ------------------------------------------------------------------ paths -- */

/**
 * '' when no data store has been chosen — callers must check
 * staxx_store_ready() rather than treating an empty string as a workable
 * path. There is no flash fallback any more: blank has to mean "not chosen"
 * so the first-run dialog and its gates can key off it.
 */
function staxx_stack_root(): string {
  $store = staxx_store_root();
  return $store === '' ? '' : $store.'/stacks';
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
function staxx_browse_dirs(string $path, string $purpose = ''): array {
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
  $dirs = array_values($dirs);

  /* When the picker is choosing where the STACKS go, most of what /mnt holds
   * is a dead end: sixteen array disks on a big server, plus the two folders
   * that only ever hold mount points. Marked rather than removed, so somebody
   * looking for disk8 can see it and read why it is out — concealing it would
   * just read as a bug.
   *
   * Only ever the top level. Deeper down the rules that bite are about shares,
   * and those are answered by the destination box itself, which must stay the
   * single voice on whether a path can be used.
   */
  $blocked = [];
  $guided  = !function_exists('staxx_placement_guided') || staxx_placement_guided();
  if ($purpose === 'stacks' && $real === $root) {
    foreach ($dirs as $entry) {
      $why = '';
      if (!$guided) {
        // Nothing is marked with the rules set to 'open' — except a memory
        // filesystem, below, which is refused whatever this says.
      } elseif ($entry === 'user0' || preg_match('/^disk[0-9]+$/', $entry)) {
        $why = 'part of the array — one disk has no redundancy of its own';
      } elseif ($entry === 'disks' || $entry === 'remotes') {
        $why = $entry === 'disks'
          ? 'unassigned drives, which can be missing at the next boot'
          : 'network mounts, which can be missing at the next boot';
      }
      if ($why === '' && function_exists('staxx_path_in_memory') && staxx_path_in_memory($root.'/'.$entry)) {
        /* Anything whose filesystem lives in memory, which on Unraid catches
         * /mnt/rootshare and any leftover folder that is not a real mount. The
         * validator refuses these on save for the same reason; catching them
         * here saves somebody browsing into one first.
         *
         * Guarded because that function lives in Settings.php, which requires
         * THIS file rather than the other way round — requiring it back would
         * be a loop. The endpoint always has it loaded; a caller that does not
         * simply gets one fewer row marked, never a wrong one. */
        $why = 'a filesystem living in memory — anything here is lost at the next reboot';
      }
      if ($why !== '') $blocked[$entry] = $why;
    }
  }

  return [
    'path'    => $real,
    'up'      => $real === $root ? '' : dirname($real),
    'dirs'    => $dirs,
    'more'    => $more,
    'blocked' => $blocked,
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
function staxx_browse_mkdir(string $parent, string $name, string &$error, string $purpose = ''): string {
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

  /* Never invent a share. A new folder directly on a pool, or in /mnt/user,
   * becomes a share Unraid discovers on its own, with whatever defaults happen
   * to apply — which is the one thing staxx_folder_create() refuses everywhere
   * else, and this button could quietly do. Two segments below /mnt is a
   * share's own root ("user/appdata", "m2cache/appdata"); anything shallower
   * than that is where a share would be created.
   *
   * Scoped to the stacks purpose, because the same picker fills in a
   * container's volume paths, where making a folder on a pool is ordinary.
   */
  if ($purpose === 'stacks') {
    $rel   = trim(substr($real, strlen($root)), '/');
    $depth = $rel === '' ? 0 : count(explode('/', $rel));
    if ($depth < 2) {
      $error = 'A folder made here would become a new Unraid share, with whatever settings '
             . 'happen to apply to it. Make the share itself on the Shares page first, then '
             . 'this folder can be made inside it.';
      return '';
    }
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
 * ok | missing | offroot for an absolute path staxx_resolve_host_path() has
 * already decided sits outside STAXX_BROWSE_ROOT (/mnt) — so this asks where
 * it is rather than measuring it against any create-root.
 *
 * A location under one of the system mounts below is legitimate whatever its
 * type — Adrian's own box bind-mounts a socket and a symlinked file from
 * exactly this set — so existence is the only question, asked with
 * file_exists() rather than is_dir()/realpath(): it follows a symlink (a
 * broken one reads as "missing"), and never forces a directory-shaped answer
 * onto a socket or a plain file. Never "file", never "inuse" — "inuse" needs
 * a directory listing, and listing arbitrary places off the array is a
 * disclosure with no benefit.
 *
 * Anywhere else is "offroot", whether or not it exists — existence alone
 * would go quiet the moment Docker creates the missing folder, which is
 * exactly when a mistyped /mnt path matters most. /boot is deliberately not
 * on the list: it survives a reboot, so it isn't the "gone at next boot"
 * problem this verdict is about, but it's flash storage a container
 * shouldn't be writing to either — the browser gives it its own wording by
 * checking the path text itself, not a verdict this function could return.
 *
 * The allowlist is matched on the lexically collapsed path so "/var/run"
 * matches as written even though it is usually a symlink to "/run", and only
 * on whole segments so "/etc" matches "/etc/localtime" but never "/etcetera".
 */
function staxx_offroot_verdict(string $target): string {
  static $allowlist = ['/dev', '/sys', '/proc', '/run', '/var/run', '/etc'];

  // Collapsed once and used for both the allowlist test and the stat: asking
  // about a different string from the one just judged is how the two come to
  // disagree — "/mnt/user/../../etc/x" is allowlisted as "/etc/x" and must be
  // looked for there too, not at a spelling whose middle may not exist.
  $lexical = staxx_lexical_path($target);
  foreach ($allowlist as $prefix) {
    if ($lexical === $prefix || strpos($lexical, $prefix.'/') === 0) {
      return file_exists($lexical) ? 'ok' : 'missing';
    }
  }
  return 'offroot';
}

/**
 * Turn one path exactly as written in a compose file into an absolute path,
 * and say which root it has to land inside. The single resolution step
 * staxx_check_paths() and the make-paths action both need done
 * identically, so what the editor underlines is exactly what the button can
 * create.
 *
 * An absolute path that lexically collapses inside STAXX_BROWSE_ROOT (/mnt —
 * the same root staxx_browse_dirs() confines itself to) is returned with that
 * root, judged by staxx_path_verdict() exactly as before: create-eligible,
 * full containment checks, "inuse" included. An absolute path anywhere else
 * comes back with $root null — there is no create-root to measure it
 * against, so it is left for staxx_check_paths() to judge by location instead
 * (see staxx_offroot_verdict()). A relative path resolves against the
 * stack's own folder, checked against the stack root instead — unchanged by
 * any of this; that only has somewhere safe to resolve against when $rel
 * names a real stack, so an invalid $rel is left with nothing to resolve
 * against rather than a guess at what it might have meant.
 *
 * Returns null for anything with nowhere sound to resolve against: a null
 * byte, an empty string, or a relative path with no valid stack behind it.
 * staxx_check_paths() turns that into "skipped".
 *
 * @return array{path:string, root:?string}|null
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
    // A /mnt that will not resolve means nothing can be placed against it, so
    // nothing is judged at all. Falling through to the by-location branch
    // instead would report every path on the machine as off-root the one time
    // /mnt is the thing that is missing — every volume in every stack flagged
    // at once, for a fault that is not in any of them.
    if ($root === false) return null;

    $inMnt = fn(string $real) => $real === $root || strpos($real, $root.'/') === 0;
    if ($inMnt(staxx_lexical_path($trimmed))) return ['path' => $trimmed, 'root' => $root];

    // Outside /mnt: not measured against a create-root at all —
    // staxx_check_paths() judges it by location.
    return ['path' => $trimmed, 'root' => null];
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
 * hostile — see staxx_resolve_host_path() for how it is placed. A path
 * inside /mnt, or a relative one inside the stack root, is judged by
 * staxx_path_verdict() exactly as it always was. An absolute path outside
 * /mnt has no create-root to be judged against, so staxx_offroot_verdict()
 * judges it by location instead: "ok" or "missing" under a known system
 * mount, "offroot" everywhere else — flagged whether or not it exists,
 * because Docker will have already created a mistyped one by the second
 * start. Anything that fails to resolve at all is "skipped" — see
 * staxx_resolve_host_path(). This does no shelling out and no directory
 * listing, only file_exists()/realpath()/is_dir() — one stat's worth of
 * work per path.
 *
 * $checkInUse asks staxx_path_verdict() to report an existing non-empty
 * folder as "inuse" rather than "ok" — pass true only for a stack being
 * created, where that folder is someone else's live data rather than the
 * stack's own. Off by default: an existing stack's volumes are expected to
 * be full of its own data, and flagging those would be noise. Never asked
 * of an offroot path — see staxx_offroot_verdict().
 *
 * @param string[] $paths host paths exactly as written in the compose file
 * @param string   $rel   the editing stack's own relative path, or ''
 * @return array<string, string> original path string => ok|file|missing|skipped|inuse|offroot
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
    if ($resolved === null) {
      $out[$p] = 'skipped';
    } elseif ($resolved['root'] === null) {
      $out[$p] = staxx_offroot_verdict($resolved['path']);
    } else {
      $out[$p] = staxx_path_verdict($resolved['path'], $resolved['root'], $checkInUse);
    }
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
 * Whether a save that means to create something may go ahead, and why not
 * when it may not: '' to proceed, else the sentence to show.
 *
 * $adopt is the caller claiming it means to give an existing but fileless
 * stack folder its first compose file, rather than to create a new stack.
 * That claim only ever NARROWS the name-clash refusal below — which is what
 * stops "Add stack" writing into a stack somebody else already owns — so it
 * is deliberately not inferred from the directory's own state.
 *
 * A function rather than a few lines inside the endpoint's switch, because a
 * refusal reachable only over HTTP is a refusal no suite on this box can
 * prove; tests/server/adopt.php calls this directly.
 */
function staxx_create_refusal(string $name, bool $adopt): string {
  $dir    = staxx_stack_dir($name);
  $exists = is_dir($dir);

  if (!$exists) {
    return $adopt
      ? 'There is no folder called "'.$name.'" any more. Refresh the stack list and look again.'
      : '';
  }

  if (!$adopt) {
    return 'A stack called "'.$name.'" already exists. Pick another name, or edit the '
         . 'existing one.';
  }

  // Asked the same way the lister asks it — a second guess at which filenames
  // count is exactly how this refusal gets bypassed by a file the lister can
  // see and this gate cannot.
  $have = staxx_find_compose_file($dir);
  if ($have !== '') {
    return 'This folder already has a compose file ('.basename($have).'), so there is nothing '
         . 'to start here. Use Edit to change it instead.';
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
 * The override basename this stack's main file would pair with, whether or
 * not that override exists yet — same rule staxx_compose_files() itself
 * follows on the way in: same directory, same base name, same extension.
 * Used to check a first-ever override save, when staxx_compose_files() has
 * nothing on disk yet to report as $pair[1].
 */
function staxx_expected_override_basename(string $main): string {
  if ($main === '') return '';
  $dot = strrpos($main, '.');
  if ($dot === false) return '';
  $ext = substr($main, $dot + 1);
  if (!in_array($ext, ['yaml', 'yml'], true)) return '';
  return basename(substr($main, 0, $dot)).'.override.'.$ext;
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
 * PLAN_68 Part C: an empty 'stacks' array must never be read as "there are no
 * stacks" when the real answer is "the root could not be looked at" — a
 * missing directory (an unmounted pool, an array not started) and one that
 * exists but will not scandir() both mean the same thing to a caller, and
 * both are folded into 'ok' => false here so nobody downstream has to
 * re-derive it from is_dir() by hand. 'ok' => true with empty arrays is the
 * genuinely different case: the root was read and there is nothing in it.
 *
 * @return array{stacks: array<int, array{rel:string, dir:string, folder:string, leaf:string}>,
 *               folders: string[], ok: bool, error: string}
 */
function staxx_scan_stacks(bool $reset = false): array {
  static $cache = null;
  if ($reset) { $cache = null; return ['stacks' => [], 'folders' => [], 'ok' => true, 'error' => '']; }
  if ($cache !== null) return $cache;

  $root = staxx_stack_root();
  $out  = ['stacks' => [], 'folders' => [], 'ok' => true, 'error' => ''];
  if (!is_dir($root)) {
    $out['ok']    = false;
    $out['error'] = 'The stack folder does not exist or is not mounted right now.';
    return $cache = $out;
  }

  // is_dir() above only proves the path is a directory, not that it can be
  // read — scandir() returns false, not an empty array, when it can't, and
  // (array)@scandir(...) used to fold that silently into "no stacks". A pool
  // that is mounted but unreadable (or drops out mid-request) hits this,
  // not just a missing one.
  $top = @scandir($root);
  if ($top === false) {
    $out['ok']    = false;
    $out['error'] = 'The stack folder exists but could not be read.';
    return $cache = $out;
  }

  foreach ($top as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (!staxx_valid_name($entry)) continue;

    $dir = $root.'/'.$entry;
    // is_dir() follows a symlink, and everything downstream — archive,
    // delete, rmtree — assumes the directory it was handed is the thing it
    // may act on. A linked stack must never reach that code as a stack.
    if (is_link($dir) || !is_dir($dir)) continue;

    if (staxx_find_compose_file($dir) !== '') {
      $out['stacks'][] = ['rel' => $entry, 'dir' => $dir, 'folder' => '', 'leaf' => $entry];
      continue;
    }

    $out['folders'][] = $entry;

    foreach ((array)@scandir($dir) as $kid) {
      if ($kid === '.' || $kid === '..') continue;
      if (!staxx_valid_name($kid)) continue;

      $kidDir = $dir.'/'.$kid;
      // Same reasoning as the top-level check above: a linked folder member
      // must not be treated as a stack directory either.
      if (is_link($kidDir) || !is_dir($kidDir)) continue;

      $out['stacks'][] = [
        'rel' => $entry.'/'.$kid, 'dir' => $kidDir, 'folder' => $entry, 'leaf' => $kid
      ];
    }
  }

  natcasesort($out['folders']);
  $out['folders'] = array_values($out['folders']);
  return $cache = $out;
}

/**
 * Drop the cached tree walk so the next staxx_scan_stacks() call re-reads
 * disk, rather than a second static living alongside it.
 *
 * Called after every save, rename and archive below as a belt-and-braces
 * measure, though none of them actually need it: one request is one PHP
 * process here, and every one of those functions already reads the tree
 * before it writes, never after, in the same request — the browser always
 * makes a fresh 'rows' request to see the result. This only protects a
 * future caller that reverses that order. Folder moves and imports (in
 * Folders.php and Import.php) follow the same read-before-write shape but
 * sit outside this file, so they rely on the same "next request re-scans"
 * guarantee rather than calling this.
 */
function staxx_scan_stacks_reset(): void {
  staxx_scan_stacks(true);
}

/**
 * The same "could this even be looked at?" answer staxx_scan_stacks() carries
 * in its own 'ok' key, for a caller holding only staxx_list_stacks()'s plain
 * array — which has no room for the flag, since it's an empty list either
 * way. Reads the same statically-cached scan, so calling this after
 * staxx_list_stacks() in the same request costs nothing further.
 */
function staxx_stacks_visible(): bool {
  return staxx_scan_stacks()['ok'];
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

  // A tail ("jellyfin/compose.yaml") only stands in safely for a moved
  // stack's real path while it points at one project. The moment a second
  // project's file produces the same tail — two stacks sharing a leaf name
  // in different folders — neither guess is trustworthy, so the entry is
  // removed rather than left pointing at whichever project happened to be
  // listed second.
  $ambiguousTails = [];

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

      $tail = staxx_file_tail($file);
      if (isset($ambiguousTails[$tail])) continue;
      if (isset($state['byTail'][$tail]) && $state['byTail'][$tail]['name'] !== $entry['name']) {
        unset($state['byTail'][$tail]);
        $ambiguousTails[$tail] = true;
        continue;
      }
      $state['byTail'][$tail] = $entry;
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
 * NOT a YAML parser, and it should never be pointed at a file a user wrote. It
 * is normally fed the output of `docker compose config`, which is CANONICAL
 * yaml: two-space indentation throughout, no anchors, no aliases, no flow
 * style for mappings, every shorthand and override already resolved. Compose
 * guarantees that shape, which is what makes reading it by hand reasonable —
 * the repository's own 07-yaml-quirks stack exists precisely to break
 * anything that tries this on a hand-written file. (PHP on Unraid has no YAML
 * extension, and compose's JSON output cannot be used: it silently drops
 * service-level `x-` fields, which are the whole reason for reading the file.)
 *
 * The one exception, deliberate and documented at its call site
 * (staxx_compose_meta()): with compose not installed there is nothing able to
 * produce that canonical form at all, and the choice is between reading the
 * hand-written file poorly or not listing anything for that stack — so it is
 * fed here anyway, and a file using anchors or flow style is read worse than
 * it would be with compose available. Nothing from that pass is ever cached.
 *
 * Anything it does not understand — sequences, tags — is SKIPPED rather than
 * guessed at. A missing key reads as "not set", which is a correct answer; a
 * guessed one is not. A block scalar is the one exception, collapsed to a
 * single line — see staxx_flatten_block() below for why.
 *
 * Paths are joined with a null byte, which cannot appear in a YAML key.
 *
 * @return array<string,string>
 */
function staxx_yaml_flatten(string $yaml): array {
  $out   = [];
  $path  = [];            // list of [indent, key] currently open
  $skip  = null;          // indent to skip past, or null
  $block = null;          // lines gathered for a block scalar, or null outside one
  $lines = explode("\n", $yaml);

  foreach ($lines as $raw) {
    $line = rtrim($raw, "\r");
    if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

    $indent = strlen($line) - strlen(ltrim($line, ' '));

    // Inside something we chose not to read — a sequence — or inside a block
    // scalar, whose lines are gathered rather than dropped. Either ends at
    // the first line indented no further than the key that opened it.
    if ($skip !== null) {
      if ($indent > $skip) {
        if ($block !== null) $block[] = $line;
        continue;
      }
      if ($block !== null) {
        $text = staxx_flatten_block($block);
        if ($text !== '') $out[implode("\0", array_column($path, 1))] = $text;
        array_pop($path);                                 // a scalar opens nothing
        $block = null;
      }
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

    // A block scalar (`|` literal or `>` folded, with any chomping or indent
    // suffix). Its lines are gathered above rather than dropped: the "fill in
    // this stack's details" chooser reads x-unraid.overview, which converted
    // files write this way, and dropping it made the chooser see an empty
    // description and then refuse to write over the one already there.
    // $path stays open until the block ends, exactly as a nested mapping's does.
    if (preg_match('/^[|>][+-]?\d*$/', $value)) { $skip = $indent; $block = []; continue; }

    if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")) {
      $value = substr($value, 1, -1);
    }

    $out[implode("\0", array_column($path, 1))] = $value;
    array_pop($path);                                     // a scalar opens nothing
  }

  // A block scalar can be the last thing in the file, with no following line
  // to notice it has ended. Closed out here instead.
  if ($block !== null) {
    $text = staxx_flatten_block($block);
    if ($text !== '') $out[implode("\0", array_column($path, 1))] = $text;
  }

  return $out;
}

/**
 * One block scalar's gathered lines, reduced to the single line of prose
 * every reader of such a value actually wants — nothing here cares about the
 * literal-versus-folded distinction or about preserved line breaks, so the
 * lines are trimmed and rejoined with one space rather than reproduced.
 *
 * Cut in CHARACTERS, not bytes, for the same reason staxx_detail_collapse()
 * is: this answer is json_encode'd into the on-disk meta cache, and a
 * byte-wise cut through the middle of a multi-byte character produces text
 * json_encode() refuses outright — losing the whole stack's cached answer
 * rather than shortening one description. An accented description is not
 * unusual. The cap itself exists because an author's free text has no length
 * limit of its own and this is written to disk once per stack.
 */
function staxx_flatten_block(array $block): string {
  $text = trim((string)preg_replace('/\s+/', ' ', implode(' ', array_map('trim', $block))));
  return mb_strlen($text, 'UTF-8') <= 4096 ? $text : mb_substr($text, 0, 4096, 'UTF-8');
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
 * matters. They are still COUNTED, though (PLAN_84): a guess at a web
 * address is only safe when the port it names is the service's only one.
 *
 * @return array<string, array{target:string, published:string, count:int}>
 *         service name => its first port, plus how many it publishes in all.
 *         A service that publishes none has no entry.
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
  $count       = 0;         // how many items this service's ports: holds
  $target = ''; $published = '';

  // Saved whenever we are about to leave the ports block, however that
  // happens — a sibling key, the next service, or the end of services: — so
  // it only needs writing in one place.
  $save = function () use (&$out, &$service, &$target, &$published, &$count) {
    if ($service !== null && $target !== '') {
      $out[$service] = ['target' => $target, 'published' => $published, 'count' => $count];
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
      $donePorts   = false; $count = 0; $target = ''; $published = '';
      continue;
    }

    if ($service === null) continue;

    if ($indent === $serviceIndent + 2) {
      // A direct property of the service — only 'ports:' matters here.
      $save();
      $inPorts     = ($body === 'ports:');
      $portsIndent = $indent;
      $itemIndent  = null; $donePorts = false; $count = 0;
      $target = ''; $published = '';
      continue;
    }

    if (!$inPorts || $indent <= $portsIndent) continue;

    $isItem = strncmp($body, '- ', 2) === 0 || $body === '-';

    // Counting continues past the first item even though reading stops
    // there: PLAN_84's web-address guess needs to know whether the port it
    // found is the ONLY one, and a service publishing two is exactly the
    // coin toss that rule refuses. Tallied here rather than by a second
    // walk of the same text — one reader, one answer.
    if ($isItem && ($itemIndent === null || $indent === $itemIndent)) $count++;
    if ($donePorts) continue;

    if ($isItem) {
      if ($itemIndent === null) {
        $itemIndent = $indent;               // the first item — read it
      } elseif ($indent === $itemIndent) {
        $donePorts = true;                   // a second item — stop reading
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

// Bumped whenever the parsing below changes shape. Folded into every cache
// key, so a plugin update cannot serve an answer the old parser computed —
// without this a stale shape would sit there looking valid forever, since
// nothing else about the compose file need have changed.
const STAXX_META_VERSION = 6;

/**
 * A hash of everything that can change what compose would report for a
 * stack: the main file's contents, the override's if there is one, its
 * .env if there is one, and STAXX_META_VERSION.
 *
 * Contents, not size-and-mtime. Stacks live on the flash drive's vfat
 * filesystem, which only records a modified time to the nearest two
 * seconds — an edit inside that window that happened to keep the same
 * length would be invisible to a timestamp check. Every file here is a few
 * kilobytes at most, so reading them costs nothing next to the `docker
 * compose` call this cache exists to avoid.
 *
 * Returns null to mean "never cache this stack": when any file mentions
 * `include:` or `extends:`, the answer depends on a file this key cannot
 * see, because compose does not report what it read in.
 */
function staxx_meta_cache_key(array $files): ?string {
  $parts = [(string)STAXX_META_VERSION];

  foreach ($files as $f) {
    $text = @file_get_contents($f);
    if ($text === false) return null;
    if (preg_match('/(?:^|\n)\s*(?:include|extends)\s*:/', $text)) return null;
    $parts[] = md5($text);
  }

  $envFile = dirname($files[0]).'/.env';
  $envText = is_file($envFile) ? @file_get_contents($envFile) : '';
  $parts[] = md5((string)$envText);

  return md5(implode("\0", $parts));
}

/**
 * Write a stack's remembered answer, temp-then-rename so a reader never
 * sees half a file — same pattern the stats snapshots use. Every step is
 * guarded: a cache that cannot be written must fall back to asking compose
 * again, silently, never fail the page.
 */
function staxx_meta_cache_write(string $path, string $key, array $meta): void {
  if (!is_dir(STAXX_META_DIR) && !@mkdir(STAXX_META_DIR, 0755, true)) return;

  $json = json_encode(['key' => $key, 'meta' => $meta]);
  if ($json === false) return;

  $tmp = $path.'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $json) === false) return;
  @rename($tmp, $path);
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
 * Answers are also remembered on disk between page loads (see
 * STAXX_META_DIR) — one file per stack, so the cache cannot grow past the
 * number of stacks and never needs pruning. A content hash decides whether
 * the remembered answer still applies; see staxx_meta_cache_key().
 *
 * @return array{ok:bool, error:?string, x:array<string,string>,
 *                services:array<string,array{image:string, container_name:string,
 *                                            x:array<string,string>, fixedIp:string,
 *                                            firstPort:array{target?:string,published?:string,count?:int},
 *                                            netMode:string, networks:string[], healthcheck:bool}>}
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

  // The remembered-on-disk answer, checked before shelling out at all.
  // $diskPath is named after the MAIN file, so a stack always has exactly
  // one entry regardless of how many times its override comes and goes.
  $diskPath = $files !== [] ? STAXX_META_DIR.'/'.md5($files[0]).'.json' : '';
  $metaKey  = $files !== [] ? staxx_meta_cache_key($files) : null;

  if ($diskPath !== '' && $metaKey !== null) {
    $stored = @json_decode((string)@file_get_contents($diskPath), true);
    if (is_array($stored) && ($stored['key'] ?? null) === $metaKey && is_array($stored['meta'] ?? null)) {
      $error = $stored['meta']['error'] ?? null;
      return $cache[$key] = $stored['meta'];
    }
  }

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
      // the file were fine. Never written to disk: only an answer compose
      // actually produced is worth remembering.
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
    // One level deeper than a flat key — a block such as `update: {mode, delay}`
    // — arrives dotted rather than being silently dropped, e.g. `update.mode`.
    if ($parts[0] === 'x-unraid' && count($parts) === 3) {
      $meta['x'][$parts[1].'.'.$parts[2]] = $value;
      continue;
    }

    if ($parts[0] !== 'services' || count($parts) < 3) continue;
    $service = $parts[1];
    if (!isset($meta['services'][$service])) {
      $meta['services'][$service] = ['image' => '', 'container_name' => '', 'x' => [],
                                      'fixedIp' => '', 'firstPort' => [], 'netMode' => '',
                                      'networks' => [], 'healthcheck' => false];
    }

    // Which networks this service names, regardless of what else is nested
    // under each one (an ipv4_address, aliases, …) — `docker compose config`
    // always writes a service's networks: as a mapping keyed by network name,
    // so parts[3] is that name whatever comes after it. Recorded here, apart
    // from the elseif chain below, because a path can match this AND (for
    // ipv4_address) that chain's own branch — the two are not alternatives.
    if ($parts[2] === 'networks' && count($parts) >= 4 && $parts[3] !== ''
        && !in_array($parts[3], $meta['services'][$service]['networks'], true)) {
      $meta['services'][$service]['networks'][] = $parts[3];
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
    } elseif ($parts[2] === 'x-unraid' && count($parts) === 5) {
      // Same one-level widening as the stack-scope block above, e.g.
      // services.<name>.x-unraid.update.mode arrives as x['update.mode'].
      $meta['services'][$service]['x'][$parts[3].'.'.$parts[4]] = $value;
    } elseif ($parts[2] === 'networks' && count($parts) === 5 && $parts[4] === 'ipv4_address') {
      // A service on more than one network with more than one fixed address
      // is not a case worth ranking — the first one found wins.
      if ($meta['services'][$service]['fixedIp'] === '') {
        $meta['services'][$service]['fixedIp'] = $value;
      }
    } elseif ($parts[2] === 'network_mode' && count($parts) === 3) {
      // The file's only word on which kind of network a never-started service
      // is on — staxx_service_net_kind() falls back to this when there is no
      // running container to ask instead.
      $meta['services'][$service]['netMode'] = $value;
    } elseif ($parts[2] === 'healthcheck' && count($parts) >= 4 && $parts[3] === 'test') {
      // PLAN_108 — only a real test command counts as "already has one";
      // `healthcheck: {disable: true}` on its own is not that, the same
      // reading applied to an image's own ["NONE"].
      $meta['services'][$service]['healthcheck'] = true;
    }
  }

  // A separate pass: ports live in a sequence, which the flattener above
  // never sees at all (see staxx_first_ports()'s own comment for why).
  foreach (staxx_first_ports($yaml) as $service => $port) {
    if (!isset($meta['services'][$service])) {
      $meta['services'][$service] = ['image' => '', 'container_name' => '', 'x' => [],
                                      'fixedIp' => '', 'firstPort' => [], 'netMode' => '',
                                      'networks' => [], 'healthcheck' => false];
    }
    $meta['services'][$service]['firstPort'] = $port;
  }

  // Only a real, compose-produced answer is remembered — never the
  // file_get_contents fallback above, which is a guess for when compose
  // itself is missing.
  if ($diskPath !== '' && $metaKey !== null && $cmd !== '') {
    staxx_meta_cache_write($diskPath, $metaKey, $meta);
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

/**
 * PLAN_71 Stage 2 — one stack's resolved config fingerprints, service =>
 * 64-hex hash, straight from `compose config --hash='*'`. This is the "file
 * side" that a container's own `com.docker.compose.config-hash` label is
 * compared against to say whether a restart is pending.
 *
 * Null means "unknown", never "everything agrees": compose not installed, a
 * non-zero exit, or output nothing here recognises. An empty array is never
 * returned for a failure, because downstream a match set with nothing in it
 * reads as "nothing has changed".
 *
 * Cached the same way staxx_compose_meta() is — in memory for the request,
 * and on disk under STAXX_META_DIR keyed by staxx_meta_cache_key(), so a
 * touched-but-unchanged file costs nothing and only a real edit pays the
 * ~66ms `compose config` takes. A distinct filename suffix (.hash.json)
 * keeps this record from colliding with staxx_compose_meta()'s own.
 */
function staxx_service_hashes(string $file): ?array {
  static $cache = [];

  $files = staxx_compose_files($file);
  if ($files === []) return null;
  $key = implode("\0", $files);
  if (isset($cache[$key])) return $cache[$key];

  $diskPath = STAXX_META_DIR.'/'.md5($files[0]).'.hash.json';
  $metaKey  = staxx_meta_cache_key($files);

  if ($metaKey !== null) {
    $stored = @json_decode((string)@file_get_contents($diskPath), true);
    if (is_array($stored) && ($stored['key'] ?? null) === $metaKey && is_array($stored['meta'] ?? null)) {
      return $cache[$key] = $stored['meta'];
    }
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') return $cache[$key] = null;

  $code = 1;
  $out  = staxx_sh($cmd.' '.staxx_compose_file_args($files)." config --hash='*' 2>&1", 15, $code);
  if ($code !== 0) return $cache[$key] = null;

  $hashes = [];
  foreach (explode("\n", $out) as $line) {
    // "<service><whitespace><64-hex-hash>" — anything else on a line (a
    // warning compose printed to stdout, a blank line) is ignored rather
    // than treated as a service with a malformed name.
    if (preg_match('/^(\S+)\s+([0-9a-f]{64})$/', trim($line), $m)) {
      $hashes[$m[1]] = $m[2];
    }
  }
  if ($hashes === []) return $cache[$key] = null;   // nothing parsed: unknown, not empty

  if ($metaKey !== null) staxx_meta_cache_write($diskPath, $metaKey, $hashes);
  return $cache[$key] = $hashes;
}

/**
 * The newest modification time across every file that feeds this stack's
 * config (main file, plus override if there is one) — "when the file was
 * last changed", for the restart-pending panel.
 */
function staxx_compose_files_mtime(string $file): int {
  $newest = 0;
  foreach (staxx_compose_files($file) as $f) {
    $mtime = @filemtime($f);
    if ($mtime !== false && $mtime > $newest) $newest = $mtime;
  }
  return $newest;
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
 *                          parses:bool, error:?string, review:bool, handover:bool,
 *                          takeover:bool}>
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
      // What the browser's menu uses to decide whether to offer "Take over
      // and start" — cheap for the same reason staxx_foreign_holders()'s own
      // docblock gives: both reads behind it are statically cached.
      'takeover' => staxx_foreign_holders($found['rel']) !== [],
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

  // staxx_docker_ps_raw() (Defines.php) is the one `docker ps -a` this and
  // staxx_containers_by_project() and staxx_docker_container_names() all
  // read from, rather than each running its own — StacksPage.php used to
  // trigger three separate ones in consecutive lines.
  foreach (staxx_docker_ps_raw() as $r) {
    if ($r['project'] === '') continue;             // not compose-managed

    $row = [
      'id'         => $r['id'],
      'name'       => $r['name'],
      'state'      => $r['state'],
      'status'     => $r['status'],
      'image'      => $r['image'],
      'project'    => $r['project'],
      'service'    => $r['service'],
      'configHash' => $r['configHash'] ?? '',
      'health'     => $r['health'] ?? 'none',
    ];

    $index['byProject'][$row['project']][] = $row;

    // A project can be built from several files ("a.yml,b.yml"); it is listed
    // under each, the same way staxx_compose_ls() does it.
    foreach (explode(',', $r['configFiles']) as $file) {
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
 * What kind of network a service is reachable on: 'bridge', 'host' or
 * 'other' (macvlan/ipvlan — a real address of its own, same as host for the
 * purpose of which port answers).
 *
 * Best evidence first. A running container's own driver settles it outright;
 * failing that, `network_mode` from the compose file is the next best thing;
 * failing that — a service that has never been started and declares no mode
 * — bridge, compose's own default. The unknown case always falls to bridge,
 * never to "other": guessing "other" for what is actually a bridge sends the
 * link to the container's own port, which is behind a private address nobody
 * outside the container can reach and so simply does not work.
 *
 * Deliberately NOT consulted: the service's own `networks:` list. Resolving
 * a network name to a driver needs staxx_network_drivers(), which is keyed
 * by the DOCKER network name — compose prefixes a project name onto
 * whatever the file calls it, so the file's name is not a safe lookup key.
 * The browser has the full live list and can do this properly; server-side,
 * only a container that has actually run can answer, and a macvlan service
 * that has never started is the one case this leaves imperfect.
 *
 * @param array  $service     one entry of staxx_compose_meta()['services']
 * @param string $liveMode    staxx_container_net()[id]['mode'], or '' when none
 * @param string $liveDriver  staxx_container_net()[id]['driver'], or '' when none
 */
function staxx_service_net_kind(array $service, string $liveMode = '', string $liveDriver = ''): string {
  if ($liveDriver !== '') {
    if ($liveDriver === 'host') return 'host';
    if ($liveDriver === 'macvlan' || $liveDriver === 'ipvlan') return 'other';
    return 'bridge';
  }

  if ($liveMode !== '') {
    if ($liveMode === 'host') return 'host';
    // A mode this cannot resolve a driver for (a custom network name, most
    // often) must not be guessed as anything other than bridge — guessing
    // "other" here would send a working bridge link to the wrong port.
    return 'bridge';
  }

  $netMode = (string)($service['netMode'] ?? '');
  if ($netMode === 'host') return 'host';
  return 'bridge';   // covers 'none' too — no link either way, so it does not matter
}

/**
 * Pull the port out of a web address's authority part (the bit between
 * `scheme://` and the first `/`), or '' when that part has no port.
 *
 * The scheme's own `://` must never be mistaken for a port separator, so it
 * is stripped first. Anything after the first `/` is a path, not part of the
 * host, so a colon or digits inside it (e.g. a path ending `/8080`, or one
 * containing a colon) must never be read as a port — that slash is cut off
 * before the colon search ever runs.
 *
 * @param string $address a webui address with `[IP]` already substituted
 */
function staxx_webui_literal_port(string $address): string {
  $rest      = preg_replace('#^https?://#i', '', $address);
  $authority = strstr($rest, '/', true);
  if ($authority === false) $authority = $rest;

  // The LAST colon, not the first. An IPv6 address is written in brackets and
  // is full of colons of its own — `[::1]:80` — so searching forwards would
  // find one inside the brackets and read the rest as a port.
  $pos = strrpos($authority, ':');
  if ($pos === false) return '';

  $port = substr($authority, $pos + 1);
  return ctype_digit($port) ? $port : '';
}

/**
 * The address that opens a service's own web page, or '' when there is none
 * to open.
 *
 * `x-unraid.webui` supplies the scheme, address and port, and any path —
 * `https://`, a trailing `/admin`. A literal port written in the address —
 * `http://[IP]:80/` — is taken exactly as written; no mapping is consulted,
 * because the address is now the whole truth. This is what the compose
 * editor's web-page-port field writes, and what fixes a container whose port
 * mapping is left over from a different network and no longer means anything.
 *
 * A `[PORT:nnn]` token is the older shape, from before that field existed,
 * and still means "work it out" rather than "here is the number" — checked
 * against 64 real templates the number inside it names the host port 10
 * times, the container port 15 times, and neither 3 times, so it cannot be
 * trusted and is ignored outright. This path only exists to keep a file
 * nobody has edited yet working until it is: mazanoke's address still says
 * `[PORT:80]` while its mapping is `8686:80`, and its link needs 8686, so
 * reading the token's own number would break it.
 *
 * An address with no port anywhere — literal or token — has nothing for the
 * button to open, so it resolves to ''.
 *
 * The address itself: a running container's own address, but only on a
 * macvlan or ipvlan network, which is the only case where the container has
 * one worth having — and only the live container can supply it, since DHCP
 * means the file may not even mention one. Failing that, the fixed address
 * written into the file, if any. Failing that, this server's address.
 *
 * @param array  $service    one entry of staxx_compose_meta()['services']
 * @param string $hostIp     staxx_host_ip()
 * @param string $liveIp     staxx_container_net()[id]['addresses'][0]['ip'], or '' when none
 * @param string $liveMode   staxx_container_net()[id]['mode'], or '' when none
 * @param string $liveDriver staxx_container_net()[id]['driver'], or '' when none
 */
function staxx_webui_url(
  array $service, string $hostIp,
  string $liveIp = '', string $liveMode = '', string $liveDriver = ''
): string {
  $raw = trim((string)($service['x']['webui'] ?? ''));
  if ($raw === '') return '';

  $fixedIp = (string)($service['fixedIp'] ?? '');
  $kind    = staxx_service_net_kind($service, $liveMode, $liveDriver);

  // The live address is only ever worth taking on a network that gives the
  // container an address of its own. A bridge container's live address is a
  // published binding, which is this server — and if that binding names one
  // interface rather than all of them, it can be 127.0.0.1, which would send
  // the link to a loopback that answers nowhere but the server itself.
  $address = ($kind === 'other' && $liveIp !== '' && $liveIp !== $hostIp) ? $liveIp
           : ($fixedIp !== '' ? $fixedIp : $hostIp);

  if (strpos($raw, '[IP]') !== false) {
    if ($address === '') return '';
    $raw = str_replace('[IP]', $address, $raw);
  }

  if (strpos($raw, '[PORT:') !== false) {
    // Migration path for a file nobody has edited yet — see the doc comment
    // above for why the token's own number cannot be trusted.
    $firstPort = $service['firstPort'] ?? [];
    // Bridge reads the server-side half of the mapping; host and macvlan/ipvlan
    // publish nothing, so only the container-side half can ever answer there.
    $port = $kind === 'bridge'
      ? (string)($firstPort['published'] ?? '')
      : (string)($firstPort['target'] ?? '');
    if ($port === '') return '';
    $raw = preg_replace('/\[PORT:[^\]]*\]/', $port, $raw);
  } elseif (staxx_webui_literal_port($raw) === '') {
    // No token and no literal port either — nothing for the button to open.
    return '';
  }

  // Whatever came out has to actually be a web address. Without this check a
  // webui typed by hand — or one left with a stray token nothing replaced —
  // could turn a row button into something other than a link.
  return preg_match('/^https?:\/\//i', $raw) ? $raw : '';
}

/**
 * Correct the Address column when it would show a port the application does
 * not actually listen on.
 *
 * The image's declared (EXPOSE'd) ports are only ever a fallback, used when
 * nothing is published — and they can simply be wrong: a `PORT`-style
 * environment variable can move the running application onto a different
 * port than the one baked into the image, and Docker has no way to notice,
 * because EXPOSE is a label on the image, not a fact about what is running.
 * The compose file's own web address (resolved by staxx_webui_url()) is an
 * explicit statement of a port that answers, so when the two disagree here,
 * the web address wins.
 *
 * Deliberately narrow. Both conditions must hold, or the addresses pass
 * through untouched:
 *
 *   - nothing published   a real binding is hard fact about where the server
 *                          forwards traffic, and is never second-guessed.
 *   - the web port is NOT among the declared ones   when it IS declared,
 *                          nothing is wrong — replacing the list would only
 *                          throw away other real, declared ports (glances
 *                          exposes two; its web button opens one of them).
 *
 * @param array<int,array{ip:string,label:string,ports:string[]}> $addresses
 * @param bool     $published whether staxx_container_net()[id]['published'] found a real binding
 * @param string   $webuiUrl  staxx_webui_url()'s result for this container, or '' when none
 * @param string[] $exposed   staxx_container_net()[id]['exposed']
 * @return array<int,array{ip:string,label:string,ports:string[]}>
 */
function staxx_address_webui_override(array $addresses, bool $published, string $webuiUrl, array $exposed): array {
  if ($published || $webuiUrl === '') return $addresses;

  $port = staxx_webui_literal_port($webuiUrl);
  if ($port === '' || in_array($port, $exposed, true)) return $addresses;

  foreach ($addresses as &$a) {
    $a['ports'] = [$port];
  }
  unset($a);

  return $addresses;
}

/**
 * Resolve one running service's web address, exactly as its row's link does —
 * so "Test web page" and the row button can never disagree about where a
 * click would go.
 *
 * @param string $rel     stack path, e.g. "jellyfin" or "Media/jellyfin"
 * @param string $service the compose service name
 * @param string $error   set to a full-sentence refusal when '' comes back
 */
function staxx_webui_for(string $rel, string $service, string &$error): string {
  if (!staxx_valid_path($rel)) {
    $error = 'That stack name is not valid.';
    return '';
  }

  $dir  = staxx_stack_dir($rel);
  $file = staxx_find_compose_file($dir);
  if ($file === '') {
    $error = 'No compose file was found for that stack.';
    return '';
  }

  $meta = staxx_compose_meta($file);
  if (!isset($meta['services'][$service])) {
    $error = 'That service is not declared in this stack\'s compose file.';
    return '';
  }

  $s = [
    'name' => $rel, 'file' => $file, 'leaf' => staxx_path_leaf($rel), 'project' => '',
  ];
  $container = null;
  foreach (staxx_stack_containers($s) as $c) {
    $matched = $c['service'] !== '' ? $c['service'] : $c['name'];
    if ($matched === $service) { $container = $c; break; }
  }

  if ($container === null) {
    $error = 'That service has no container yet — start it first.';
    return '';
  }
  if ($container['state'] !== 'running') {
    $error = 'That container is not running, so it has nothing to answer with.';
    return '';
  }

  $net  = staxx_container_net()[$container['id']] ?? [];
  $url  = staxx_webui_url(
    $meta['services'][$service], staxx_host_ip(),
    (string)($net['addresses'][0]['ip'] ?? ''), (string)($net['mode'] ?? ''), (string)($net['driver'] ?? '')
  );

  if ($url === '') {
    $error = 'This file has no web address, or its address names no port — add one in the web page port field.';
    return '';
  }
  return $url;
}

/**
 * Ask whether a resolved web address answers at all, without following where
 * it might redirect to — a redirect answering is itself proof something is
 * there, and chasing it could send this server somewhere unintended.
 *
 * Modelled on staxx_hub_json(): curl's own --max-time is set below the
 * staxx_sh() budget so curl reports the failure itself rather than being
 * killed mid-flight.
 *
 * @param int|null $code set to the HTTP status curl reported, or 0 when
 *                       nothing answered within the time limit
 */
function staxx_webui_try(string $url, ?int &$code = null): bool {
  $code = 0;
  // Belt and braces on top of staxx_webui_url()'s own guard — this is the
  // function that hands the string to a shell.
  if (!preg_match('~^https?://~i', $url)) return false;

  $cmd = 'curl -s -o /dev/null -w \'%{http_code}\' --max-time 4 '.escapeshellarg($url);
  $out = trim(staxx_sh($cmd, 7));

  $code = ctype_digit($out) ? (int)$out : 0;
  return $code >= 100;
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
 * `exposed` is kept for every container, not only ones with no published
 * binding — the port-row suggestion in the compose form reads it (carried
 * over on the state snapshot), so it has to survive even when a published
 * binding already exists.
 *
 * `published` records which of the two situations above actually happened —
 * true only when a real binding was found — so a caller can tell "nothing
 * was published, this is a guess from the image's declared ports" apart from
 * "this is a real forwarded port", without re-deriving it from the addresses.
 *
 * @return array<string, array{mode:string, driver:string, exposed:string[], published:bool, addresses:array<int,array{ip:string, ports:string[]}>}>
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

    // Parsed once, used two ways: the fallback below (when nothing is
    // published) and the per-container `exposed` list kept regardless.
    $exposedPorts = [];
    foreach (explode(',', $exposed) as $p) {
      $p = trim($p);
      if ($p === '') continue;
      $exposedPorts[strtok($p, '/')] = true;      // "8083/tcp" -> "8083"
    }

    // Recorded before the fallback below can fill $byIp in from the exposed
    // list — otherwise a container with nothing published would look exactly
    // like one with a real binding, and the two are told apart nowhere else.
    $published = (bool)$byIp;

    // ---- or the container's own address ----
    if (!$byIp) {
      $ports = $exposedPorts;

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

    $exposedList = array_keys($exposedPorts);
    sort($exposedList, SORT_NUMERIC);

    $net[$id] = [
      'mode'      => $mode,
      'driver'    => $driver,
      'exposed'   => array_map('strval', $exposedList),
      'published' => $published,
      'addresses' => $addresses,
    ];
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
  // Memoised on the stack's name — StacksTable.php's full render and cheap
  // refresh both ask for the same stack's containers more than once within
  // one request, and nothing here can change under a stack mid-request, so
  // the repeat calls are free rather than re-filtering staxx_container_index().
  static $memo = [];
  $key = $s['name'] ?? null;
  if ($key !== null && isset($memo[$key])) return $memo[$key];

  // A stack awaiting review owns nothing, by definition — it was imported and
  // nobody has confirmed what it refers to. The project-name fallback below
  // would otherwise hand it the LIVE containers of whatever it was copied
  // from, and the row would then show their state, address, processor and
  // memory as though the import were already working. Guarded here rather
  // than at each of the three call sites so the full render and the cheap
  // state refresh cannot disagree about it.
  if ($key !== null && staxx_review_locked((string)$key)) {
    return $memo[$key] = [];
  }

  $index = staxx_container_index();

  if ($s['file'] !== '' && isset($index['byFile'][$s['file']])) {
    $result = $index['byFile'][$s['file']];
    if ($key !== null) $memo[$key] = $result;
    return $result;
  }

  // The LEAF, never the full path. Compose names a project after the directory
  // holding the file, so a stack in a folder is still "jellyfin" and never
  // "media/jellyfin" — matching on the path would find nothing the moment a
  // stack was filed.
  $leaf    = $s['leaf'] ?? staxx_path_leaf($s['name']);
  $project = $s['project'] !== '' ? $s['project'] : staxx_project_name($leaf);
  $result  = $index['byProject'][$project] ?? [];
  if ($key !== null) $memo[$key] = $result;
  return $result;
}

/**
 * PLAN_107 — rolls a stack's containers up into one health word, considering
 * only the ones actually running: a stopped container's stale health means
 * nothing, and is already covered by the ordinary running/stopped colour.
 * Unhealthy outranks starting outranks healthy, so one bad container is never
 * hidden behind another that is still coming up.
 *
 * Computed only where the caller already has the containers in hand — never
 * from staxx_stack_states(), which is deliberately one `compose ls` and no
 * file reads (see its own docblock).
 */
function staxx_stack_health(array $containers): string {
  $any = ['unhealthy' => false, 'starting' => false, 'healthy' => false];
  foreach ($containers as $c) {
    if (strtolower((string)($c['state'] ?? '')) !== 'running') continue;
    $h = $c['health'] ?? 'none';
    if (isset($any[$h])) $any[$h] = true;
  }
  if ($any['unhealthy']) return 'unhealthy';
  if ($any['starting'])  return 'starting';
  if ($any['healthy'])   return 'healthy';
  return 'none';
}

/**
 * How many of a stack's running containers check themselves, out of how many
 * are running at all. A stack row shows one pill for the lot, so without this
 * "says it is working" would quietly speak for containers nothing has ever
 * asked — which is the exact overclaim PLAN_107 exists to stop.
 *
 * @return array{running:int, checked:int}
 */
function staxx_stack_health_counts(array $containers): array {
  $running = 0;
  $checked = 0;
  foreach ($containers as $c) {
    if (strtolower((string)($c['state'] ?? '')) !== 'running') continue;
    $running++;
    if (($c['health'] ?? 'none') !== 'none') $checked++;
  }
  return ['running' => $running, 'checked' => $checked];
}

/**
 * The service names — or container names, for one with no service label —
 * that are running and unhealthy. What the tooltip on a sick stack row names.
 */
function staxx_unhealthy_services(array $containers): array {
  $out = [];
  foreach ($containers as $c) {
    if (strtolower((string)($c['state'] ?? '')) !== 'running') continue;
    if (($c['health'] ?? 'none') !== 'unhealthy') continue;
    $out[] = (string)(($c['service'] ?? '') !== '' ? $c['service'] : ($c['name'] ?? ''));
  }
  return $out;
}

/**
 * PLAN_71 Stage 3 — "restart pending": does what is running still match what
 * the file now says, service by service. Never a guess — state is '' (no
 * chip) for every case this cannot judge, not just the ones it can prove
 * match: a stopped stack, an unknown file, containers with no fingerprint.
 *
 * @param array $s one entry from staxx_list_stacks() (needs name, file)
 * @return array{state:string, changed:string[], absent:string[], leftover:string[],
 *               edited:int, services:array<string,string>}
 */
function staxx_restart_pending(array $s): array {
  static $memo = [];
  // Unlike staxx_stack_containers(), every caller here already has a real
  // stack row, so the name is never absent — no null-key special case needed.
  $key = (string)($s['name'] ?? '');
  if (isset($memo[$key])) return $memo[$key];

  $empty = ['state' => '', 'changed' => [], 'absent' => [], 'leftover' => [],
            'edited' => 0, 'services' => []];

  $containers = staxx_stack_containers($s);
  $running = false;
  foreach ($containers as $c) {
    if (strtolower($c['state']) === 'running') { $running = true; break; }
  }
  // Nothing running means nothing can be running the wrong settings —
  // no chip, by decision, not "no chip because it's unknown".
  if (!$running) return $memo[$key] = $empty;

  $hashes = $s['file'] !== '' ? staxx_service_hashes($s['file']) : null;
  if ($hashes === null) return $memo[$key] = $empty;   // unknown, never a match

  $services = [];
  $changed  = [];
  $absent   = [];
  $leftover = [];

  $seen = [];   // services a container was found for, so absent can be found by elimination
  foreach ($containers as $c) {
    $svc = (string)$c['service'];
    if ($svc === '') {
      // A container with no service label at all cannot be matched to a
      // declared service; it is leftover noise this stack cannot name.
      continue;
    }
    // A service running more than one copy (replicas) has a container each,
    // all carrying the same fingerprint. The verdict is the service's, not the
    // container's, so the first copy settles it and the rest are skipped —
    // otherwise the panel names the same service twice.
    if (isset($seen[$svc])) continue;
    $seen[$svc] = true;

    if (!array_key_exists($svc, $hashes)) {
      $leftover[] = $svc;
      $services[$svc] = 'leftover';
      continue;
    }

    $fileHash = (string)$hashes[$svc];
    $liveHash = (string)($c['configHash'] ?? '');
    if ($fileHash === '' || $liveHash === '') {
      $services[$svc] = 'unknown';
    } elseif ($fileHash !== $liveHash) {
      $changed[] = $svc;
      $services[$svc] = 'changed';
    } else {
      $services[$svc] = 'match';
    }
  }

  foreach (array_keys($hashes) as $svc) {
    if (!isset($seen[$svc])) {
      $absent[] = $svc;
      $services[$svc] = 'absent';
    }
  }

  $state = ($changed !== [] || $absent !== [] || $leftover !== []) ? 'pending' : '';

  return $memo[$key] = [
    'state'    => $state,
    'changed'  => $changed,
    'absent'   => $absent,
    'leftover' => $leftover,
    'edited'   => staxx_compose_files_mtime($s['file']),
    'services' => $services,
  ];
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
 * A content hash of the compose file as it stands on disk right now, so a
 * save can be refused if the file changed after the editor opened it —
 * another tab, the image updater, or a hand edit on the server. Same
 * md5-of-bytes idiom as the meta cache (staxx_meta_cache_key()) rather than
 * a second one, but not that function itself: this has to stay '' only when
 * there is truly no file yet, never because an unrelated .env or an
 * include:/extends: line is present, which would make an in-progress edit
 * unsaveable. Returns '' when the stack has no compose file yet.
 */
function staxx_stack_fingerprint(string $name): string {
  if (!staxx_valid_path($name)) return '';
  $file = staxx_find_compose_file(staxx_stack_dir($name));
  if ($file === '') return '';
  $body = @file_get_contents($file);
  return $body === false ? '' : md5($body);
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
  if ($tmpdir === false) {
    $error = 'Could not create a scratch file to check this against, so it was not validated. Nothing was saved.';
    return false;
  }
  @unlink($tmpdir);
  if (!@mkdir($tmpdir, 0700)) {
    $error = 'Could not create a scratch folder to check this against, so it was not validated. Nothing was saved.';
    return false;
  }

  $tmpfile = $tmpdir.'/compose.yaml';
  $written = @file_put_contents($tmpfile, str_replace("\r\n", "\n", $yaml));
  if ($written === false) {
    @rmdir($tmpdir);
    $error = 'Could not write the scratch file to check this against, so it was not validated. Nothing was saved.';
    return false;
  }

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

  // PLAN_68 Part C: a self-test that reports "0 stacks" while the array is
  // down is a healthy nothing dressed up as a fact. $scan['ok'] is false for
  // exactly the case an empty count cannot tell apart from a genuinely empty
  // stack folder, so every count built from the scan below says so plainly
  // instead of reporting zero.
  $seen = $scan['ok'];

  // Pure PHP, like everything else here — no external command, so this stays
  // instant and checkable from the server with no browser.
  $awaitingReview = 0;
  foreach ($scan['stacks'] as $found) {
    if (staxx_review_file($found['dir']) !== '') $awaitingReview++;
  }

  // PLAN_61 stage 4 — reads the update-check state file staxx_updates_moved_
  // for_stack() already caches; no network of its own. Guarded because this
  // file is loadable on its own (see the troubleshooting one-liner in
  // stacks.js), and Updates.php is then not necessarily loaded.
  $movedLines = function_exists('staxx_updates_moved_report') ? staxx_updates_moved_report() : null;
  $movedReport = $movedLines === null
    ? 'not checked — the update-checking module did not load'
    : ($movedLines === []
        ? 'none — every catalogued image is still pulling from where its template currently publishes'
        : implode("\n  ", $movedLines));

  /* PLAN (this session): are the compose files in anybody's backup? Nothing
   * backs them up by default — the Appdata Backup plugin works from each
   * container's volume mappings, and a plugin's own store is not one — so a
   * store nobody has named is a store that quietly is not covered.
   *
   * Guarded like the update-check line above, because this file is loadable on
   * its own (see the troubleshooting one-liner in stacks.js) and Backup.php is
   * then not necessarily loaded. Silence from it is a real answer and is
   * reported as such: it means no claim could be made, never "not listed".
   */
  $backupReport = 'not checked — the backup module did not load';
  if (function_exists('staxx_backup_coverage')) {
    if (!staxx_backup_plugin_installed()) {
      $backupReport = 'the Appdata Backup plugin is not installed, so there is nothing to check '
                    . 'against — back these folders up some other way: '
                    . implode(', ', staxx_backup_owned_paths());
    } else {
      $cov = staxx_backup_coverage();
      if ($cov === null) {
        $backupReport = 'the Appdata Backup plugin is installed, but its settings could not be '
                      . 'read, so nothing can be said either way';
      } elseif ($cov['missing'] === []) {
        // Deliberately not "backed up": that a folder is named in the list is
        // all this can see. Whether a run succeeds depends on their schedule
        // and an off-box destination that may not even be reachable.
        $backupReport = 'listed in the Appdata Backup plugin, under extra files — '
                      . implode(', ', $cov['listed'])
                      . ' (listed, which is not the same as proven to have been backed up)';
      } else {
        $backupReport = 'NOT listed in the Appdata Backup plugin, under extra files: '
                      . implode(', ', $cov['missing'])
                      . ' — add them there, or these compose files are in no backup';
      }
    }
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
    'stacks found'        => $seen ? (string)$dirs : 'UNKNOWN — '.$scan['error'],
    'folders found'       => $seen ? (string)$folds : 'UNKNOWN — '.$scan['error'],
    'stacks awaiting review' => $seen ? (string)$awaitingReview : 'UNKNOWN — the stacks could not be seen',
    'images pulling from a registry their template has left' => $movedReport,
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
    'compose files in a backup' => $backupReport,
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
function staxx_save_stack(string $name, string $yaml, string &$error, ?string &$note = null): bool {
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

  // Keep whatever is on disk right now before it is overwritten — this has
  // to run after every check above (a refused save must not leave a version
  // behind) and before the write below (or the version worth keeping is
  // already gone). A failure here is never allowed to block the save itself;
  // it just means this one save has no undo, and the person is told so at
  // the time rather than finding out the day they go looking for it.
  $recordNote = '';
  // basename, not the path in $file — the record resolves the stack folder
  // itself, and handing it a full path silently finds no file and keeps
  // nothing while reporting success.
  if (!staxx_record_capture($name, basename($file), $recordNote) && $recordNote !== '') {
    if ($note !== null) $note = $recordNote;
    error_log('StaXX: history not kept for '.$name.': '.$recordNote);
  }

  // Temp-then-rename, same reasoning as staxx_settings_save(): a short write
  // on a full flash drive still reports success, so the byte count actually
  // written is checked against what was asked for before the file is put in
  // place. A reader — compose, or the next open of this stack — never sees a
  // half-written file either way.
  $tmp = $file.'.'.getmypid().'.tmp';
  $written = @file_put_contents($tmp, $yaml);
  if ($written === false || $written !== strlen($yaml)) {
    @unlink($tmp);
    $error = 'Could not write '.$file;
    return false;
  }
  // Owner-only: a compose file can hold every password the containers it
  // describes were given. This is moot on /boot — that filesystem takes its
  // mode from how it is mounted, whatever chmod says — but it matters the
  // moment the data store is pointed at an array share, which the setting invites.
  @chmod($tmp, 0600);
  if (!@rename($tmp, $file)) {
    @unlink($tmp);
    $error = 'Could not save '.$file.' — the temporary file could not be put in place.';
    return false;
  }
  // Keep the file as it now stands too — not only as it stood before. The
  // before-save capture above is what catches an edit made on the server by
  // hand between two saves; without this second call the version just
  // written is never kept at all, so a save followed by a lost file loses
  // exactly the copy nobody else has. staxx_record_capture() already skips a
  // capture whose hash matches the newest one stored, so the version this
  // writes and the "before" version taken at the *next* save — the same
  // bytes — collapse into one kept version rather than two.
  $recordNote2 = '';
  if (!staxx_record_capture($name, basename($file), $recordNote2) && $recordNote2 !== '') {
    if ($note !== null && $note === '') $note = $recordNote2;
    error_log('StaXX: history not kept for '.$name.': '.$recordNote2);
  }

  // The boot-drive shelf copy (PLAN_103). Same reasoning as the history
  // capture above: the save has already succeeded by this point, and a
  // failure to copy must never undo that — it is only ever logged, and
  // surfaced as a note the same way a history that could not be kept is.
  $bootNote = '';
  if (!staxx_boot_copy_stack($name, $bootNote) && $bootNote !== '') {
    if ($note !== null && $note === '') $note = $bootNote;
    error_log('StaXX: boot copy not written for '.$name.': '.$bootNote);
  }

  // A brand new stack just changed the tree's shape; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();
  return true;
}

/**
 * Everything in a stack's folder except its compose file, for the removal
 * confirmation — what is about to be archived. A subdirectory's count is how
 * many entries it holds one level down, so the confirmation can say "3
 * files" rather than just "a folder"; a plain file's count is always 0.
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
 * The archive's own filename: the stack's path with slashes turned into
 * dashes (so "Media/jellyfin" and "jellyfin" can never collide) plus the
 * moment it was written, to a second — good enough that two archives of the
 * same stack cannot land on the same name without staxx_archive_stack()'s own
 * "-2" fallback ever being needed in practice.
 */
function staxx_archive_name(string $name): string {
  return str_replace('/', '-', $name).'-'.date('Ymd-His').'.zip';
}

/**
 * Match the Unraid user-share convention (nobody:users, 0777 for a
 * directory, 0666 for a file) so a path written under /mnt/ can be read,
 * written to or deleted by anything else on that share. Left to the web
 * server's own umask, a freshly created folder or file there comes out
 * root:root — fine for the process that made it, unreachable for everyone
 * and everything else that treats a user share as nobody:users.
 *
 * A no-op for anything not under /mnt/: the archive folder and the stack
 * root can both live on /boot instead, which is vfat and has no concept of
 * an owner, so chown there either fails outright or silently does nothing.
 * The check resolves the path first, because a path can reach /mnt/ by
 * symlink without its own text saying so.
 *
 * Every failure here is swallowed. A backup that was written but ended up
 * awkwardly owned is far better than a removal refused because a chown
 * failed on the way — this must never block an otherwise-good archive.
 */
function staxx_share_perms(string $path, bool $isDir): void {
  $real = @realpath($path);
  if ($real === false) return;
  // Strictly *under* /mnt, never /mnt itself: that is the mount root every
  // share hangs off, and handing it 0777 because an archive folder setting
  // resolved oddly would be a far worse bug than the one this fixes.
  if (strpos($real, '/mnt/') !== 0) return;

  @chown($real, 'nobody');
  @chgrp($real, 'users');
  @chmod($real, $isDir ? 0777 : 0666);
}

/**
 * Archive a stack's directory and take it out of the stacks tree.
 *
 * Removing a stack used to delete its folder outright, refusing outright if
 * it held anything beyond a compose file, a .env or the review lock — that
 * guard existed because everything else in the folder was about to be lost
 * for good. It no longer is: the whole folder, extras included, is zipped
 * into the archive folder first, and only removed from the stacks tree once
 * that zip is verified to have every file in it. Nothing here can end with
 * files gone and no archive to show for it — every failure path below
 * refuses before anything is touched, except the very last step, which only
 * removes the (now archived) folder.
 *
 * Unconfirmed, this only runs far enough to find out whether the folder holds
 * anything beyond the compose file — the caller (action.php's "archive" case)
 * uses that to show what is about to be zipped before asking for a "yes".
 * That is why $error is left empty on an unconfirmed return: there is nothing
 * wrong, the question just has not been answered yet.
 *
 * The containers are stopped first either way (once confirmed). Archiving
 * the folder while the stack is up would leave containers running that
 * nothing on this page can reach.
 *
 * @param string|null $archive set to the full path of the written zip on success
 */
function staxx_archive_stack(
  string $name, string &$error, bool $confirmed = false, ?string &$archive = null
): bool {
  $error = '';
  if (!staxx_valid_path($name)) { $error = 'Invalid stack name.'; return false; }

  $dir = staxx_stack_dir($name);
  if (!is_dir($dir)) { $error = 'No such stack.'; return false; }

  // A symlinked stack folder cannot be archived faithfully: zip stores the
  // link itself rather than its target's contents, while removing the
  // folder afterwards resolves through the link and deletes whatever it
  // points at instead. Refuse rather than guess which half the user wanted.
  if (is_link($dir)) {
    $error = 'This stack folder is a link to somewhere else, so it cannot be archived '
           . 'or removed safely. Remove the link by hand instead.';
    return false;
  }

  // A locked stack's containers, if anything answers to its name, belong to
  // whatever it was imported from — tearing them down is the exact accident
  // the review lock exists to prevent, so this skips straight to archiving
  // the folder for one, below.
  $locked = staxx_review_file($dir) !== '';

  // A handover has an old container set aside under another name, waiting for
  // an answer that only this stack's row can give. Archive the stack now and
  // that container is stranded with nothing left to say what it was called or
  // how to put it back — so the answer has to come first.
  if (staxx_handover_file($dir) !== '') {
    $error = 'This stack has just taken over from another container, and that '
           . 'container is set aside waiting for you to say whether the new one '
           . 'works. Answer that first — nothing can be removed until it is '
           . 'either cleared away or put back.';
    return false;
  }

  // The confirmation dialog needs to know what is in the folder before the
  // user says yes, but nothing below this point may run before that "yes" —
  // see the docblock above.
  if (!$confirmed) return false;

  // Where the zip goes. Nothing has been touched yet, so a folder that
  // cannot be created or written refuses the whole thing up front.
  //
  // Built one level at a time rather than mkdir(..., true) in one call, so
  // every level this creates can be handed to staxx_share_perms() below —
  // otherwise a fresh /mnt/user/appdata/staxx/archives comes out root:root
  // and nothing on the network share can read or delete what lands in it.
  $root = staxx_archive_root();
  if (!is_dir($root)) {
    $built = '';
    foreach (explode('/', trim($root, '/')) as $part) {
      $built .= '/'.$part;
      if (is_dir($built)) continue;
      if (!@mkdir($built, 0755)) break;
      staxx_share_perms($built, true);
    }
  }
  if (!is_dir($root) || !is_writable($root)) {
    $error = 'The archive folder '.$root.' does not exist or cannot be written to. '
           . 'Check the archive folder setting and try again.';
    return false;
  }
  // Correct a folder made by an earlier version of this code, which left it
  // root:root — otherwise the fix looks like it did nothing on a box that
  // already has one of these lying around.
  staxx_share_perms($root, true);

  // Size guard. A stack folder is normally a few kilobytes; this page waits
  // while the zip is built, and the plugin caps anything it runs at two
  // minutes, so a folder holding, say, a disk image needs moving by hand
  // instead. A `du` that fails or times out is treated as "small enough" —
  // silence here must never block an otherwise-good removal.
  $duCode = 0;
  $duOut  = staxx_sh('du -sk '.escapeshellarg($dir), 20, $duCode);
  if ($duCode === 0 && preg_match('/^(\d+)/', trim($duOut), $m)) {
    $kib = (int)$m[1];
    if ($kib > 256000) {
      $error = 'This stack folder is '.round($kib / 1024).' MB. A stack folder is normally '
             . 'a few kilobytes, and this page waits while it is zipped — move the folder '
             . 'by hand instead.';
      return false;
    }
  }

  // Both halves of the stack, in Docker's order — a stack running from a
  // compose file and its override must be torn down by naming both, or the
  // override's services are left up with nothing on this page able to reach
  // them. staxx_compose_file_args() on a single file is character-for-
  // character the '-f <file>' this used to write inline.
  $file  = staxx_find_compose_file($dir);
  $files = staxx_compose_files($file);

  $cmd  = staxx_compose_cmd();
  if (!$locked && $file !== '' && $cmd !== '' && staxx_docker_running()) {
    $code = 1;
    $out  = staxx_sh(
      'cd '.escapeshellarg($dir).' && '.$cmd.' '.staxx_compose_file_args($files).' down 2>&1',
      120,
      $code
    );
    // Refuse if the containers are still up. Archiving the folder while its
    // containers run leaves them orphaned, with nothing in this UI able to
    // reach them again.
    if ($code !== 0) {
      $error = 'The containers could not be stopped, so nothing was archived or removed. '
             . 'Stop the stack first, then try again.'
             . ($out !== '' ? "\n\n".trim($out) : '');
      return false;
    }
  }

  // Build the final zip name, bumping past a collision rather than
  // overwriting an earlier archive of the same stack.
  $base = staxx_archive_name($name);
  $final = $root.'/'.$base;
  if (file_exists($final)) {
    $stem = substr($base, 0, -4); // strip ".zip"
    $n = 2;
    do {
      $final = $root.'/'.$stem.'-'.$n.'.zip';
      $n++;
    } while (file_exists($final) && $n <= 99);
    if (file_exists($final)) {
      $error = 'Could not find a free archive filename for "'.$name.'". Try again in a moment.';
      return false;
    }
  }

  $tmp = $root.'/.'.basename($final).'.tmp-'.getmypid();

  // -y stores a symlink as a link rather than following it — a link inside
  // the folder must not be able to make the zip enormous or loop. The
  // integrity test runs in the same command so a failure never leaves a zip
  // that looks fine but silently isn't.
  $zipCode = 1;
  $zipOut  = staxx_sh(
    'cd '.escapeshellarg(dirname($dir)).' && '
    . 'zip -r -y -q '.escapeshellarg($tmp).' '.escapeshellarg(basename($dir)).' 2>&1 && '
    . 'unzip -tqq '.escapeshellarg($tmp).' 2>&1',
    100,
    $zipCode
  );
  if ($zipCode !== 0) {
    @unlink($tmp);
    $error = 'Could not write the archive, so nothing was removed.'
           . ($zipOut !== '' ? "\n\n".trim($zipOut) : '');
    return false;
  }

  if (!@rename($tmp, $final)) {
    @unlink($tmp);
    $error = 'The archive could not be put in place, so nothing was removed.';
    return false;
  }
  // Only the finished zip, never the ".tmp-" name — chown-ing that would be
  // wasted work on a file about to be renamed away from under it anyway.
  staxx_share_perms($final, false);

  $real = @realpath($dir);
  if ($real === false || !staxx_rmtree($real, $real)) {
    $error = 'The stack was archived to '.$final.', but its folder could not be removed. '
           . 'The archive is safe; remove the folder by hand.';
    return false;
  }

  // The stack is gone from the store, so its boot-drive shelf copy goes
  // with it (PLAN_103) — a shelf that quietly kept it would be a
  // resurrection waiting to happen, and this is what the archive above
  // exists for already. Nothing here can undo the archive that just
  // succeeded, so this only ever removes; it never reports a failure back.
  staxx_boot_remove_stack($name);

  staxx_scan_stacks_reset(); // the stack's directory is gone; see the function's own comment
  $archive = $final;
  return true;
}

/**
 * What is in the archive folder, newest first, for the settings panel.
 * Only the finished zips — a ".tmp-" one is still being written. A missing
 * folder is not an error here: it just means nothing has been archived yet.
 *
 * @return array<int, array{name:string, size:int, mtime:int}>
 */
function staxx_archive_list(): array {
  $root = staxx_archive_root();
  if (!is_dir($root)) return [];

  $out = [];
  foreach ((array)@scandir($root) as $name) {
    if ($name === '.' || $name === '..') continue;
    if (!str_ends_with($name, '.zip')) continue;
    $path = $root.'/'.$name;
    if (!is_file($path)) continue;
    $st = @stat($path) ?: [];
    $out[] = [
      'name'  => $name,
      'size'  => (int)($st['size'] ?? 0),
      'mtime' => (int)($st['mtime'] ?? 0),
    ];
  }

  usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
  return array_slice($out, 0, 100);
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
  // Per-directory, for the render loop that asks this once per stack row and
  // once per container row — a scandir() each time on a 64-stack server adds
  // up. Safe to keep for the whole request: nothing that moves or removes
  // this file re-checks it afterwards in the same request (see
  // staxx_scan_stacks_reset()'s comment for why that pattern holds here).
  static $cache = [];
  if (array_key_exists($dir, $cache)) return $cache[$dir];

  $found = '';
  foreach ((array)@scandir($dir) as $entry) {
    if (strcasecmp($entry, STAXX_REVIEW_FILE) === 0 && is_file($dir.'/'.$entry)) {
      $found = $entry;
      break;
    }
  }
  return $cache[$dir] = $found;
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
 * which is exactly what a template's own container has, so this reads the
 * full, unfiltered scan too, off the same shared `docker ps -a` as the index
 * and staxx_containers_by_project() rather than running its own.
 * (Import.php has a near-identical one; a later pass can dedupe rather than
 * this file reaching into that one.)
 *
 * @return array<string, array{running:bool, project:string}>
 */
function staxx_docker_container_names(): array {
  static $byName = null;
  if ($byName !== null) return $byName;

  $byName = [];
  foreach (staxx_docker_ps_raw() as $r) {
    if ($r['name'] === '') continue;
    $byName[$r['name']] = ['running' => $r['state'] === 'running', 'project' => $r['project']];
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
 * Which of this stack's handover targets a handover will actually accept —
 * the subset of staxx_handover_targets() whose container carries no
 * `com.docker.compose.project` label. Three callers need this exact question
 * answered (whether a takeover is on offer, and whether starting the stack
 * normally would collide with a template's own container) and must not drift
 * from what staxx_start_handover() itself allows further down. Both reads
 * behind it are statically cached, so this costs array lookups and no extra
 * shelling out.
 *
 * @return array<int, array{service:string, name:string, running:bool}>
 */
function staxx_foreign_holders(string $rel): array {
  $containers = staxx_docker_container_names();
  return array_values(array_filter(
    staxx_handover_targets($rel, $containers),
    fn($t) => ($containers[$t['name']]['project'] ?? '') === ''
  ));
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

  // Bounded rather than open-ended: at most one collision per name already
  // taken, plus a little slack, so this cannot spin forever against a
  // pathological $taken. A random suffix past that bound still guarantees
  // termination and is astronomically unlikely to collide itself.
  $limit = count($taken) + 8;
  for ($n = 2; $n <= $limit; $n++) {
    $try = $candidate.'-'.$n;
    if (!in_array($try, $taken, true)) return $try;
  }
  return $candidate.'-'.bin2hex(random_bytes(4));
}

/** The handover state file actually present in $dir, by its real name, or ''. */
function staxx_handover_file(string $dir): string {
  // Same per-directory memoisation and the same reasoning as
  // staxx_review_file() just above.
  static $cache = [];
  if (array_key_exists($dir, $cache)) return $cache[$dir];

  $found = '';
  foreach ((array)@scandir($dir) as $entry) {
    if (strcasecmp($entry, STAXX_HANDOVER_FILE) === 0 && is_file($dir.'/'.$entry)) {
      $found = $entry;
      break;
    }
  }
  return $cache[$dir] = $found;
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
  // Nothing to put back when there was no review note to begin with, which
  // is the common case: a handover started from an unlocked stack.
  if ($heldReviewPath !== '') {
    $undo[] = '  mv '.escapeshellarg($heldReviewPath).' '.escapeshellarg($reviewPath).' 2>/dev/null || true';
  }
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
  if ($heldReviewPath !== '') {
    $steps[] = 'rm -f '.escapeshellarg($heldReviewPath).' 2>&1';
  }
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

  // The review lock is deliberately not consulted. What decides whether a
  // handover is safe is the foreign-project refusal below: only a container
  // belonging to no compose project can be taken over, which is exactly a
  // template's own. Requiring the lock on top of that would make this the
  // one way out of a state the lock itself can be cleared from, since
  // clearing it does nothing about the container holding the name.
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

  // A stack with no review note is the common case. $heldPath and
  // $reviewPath stay '' and every rename below skips itself, so the note is
  // held aside and restored only when there is one.
  $reviewName = staxx_review_file($dir);
  $heldPath   = $reviewName !== '' ? $dir.'/.'.$reviewName : '';
  $reviewPath = $reviewName !== '' ? $dir.'/'.$reviewName : '';

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

  if ($reviewPath !== '' && !@rename($reviewPath, $heldPath)) {
    @unlink($dir.'/'.STAXX_HANDOVER_FILE);
    $error = 'Could not set the review note aside, so nothing was started.';
    return '';
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
    // Put both files back — nothing should look started when nothing was.
    if ($reviewPath !== '') @rename($heldPath, $reviewPath);
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
  @chmod($log, 0600);

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
    // Chained with && and the exit code caught straight away, not one echo
    // per line joined by newlines — that older shape always ran every step
    // regardless of whether an earlier one failed, and "$?" at the end was
    // always the echo's own exit status, so this job reported success no
    // matter what actually happened above it.
    $chain  = implode(' && ', $steps);
    $script = $chain.'; ec=$?; '
            . 'if [ "$ec" -eq 0 ]; then rm -f '.$stateFile.' 2>&1; '
            . 'echo "The old container has been cleared away."; fi; '
            . 'echo "'.STAXX_JOB_END.' $ec"';

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

    // This is the dangerous direction: a real failure here leaves the
    // original container renamed aside and switched off, so it must never be
    // reported as "put back" — the state file (which is what makes this
    // stack's review lock read as "handover in progress") is only cleared,
    // and the reassuring line only printed, once the whole rename/start
    // chain actually succeeded.
    $chain  = implode(' && ', $steps);
    $script = $chain.'; ec=$?; '
            . 'if [ "$ec" -eq 0 ]; then rm -f '.$stateFile.' 2>&1; '
            . 'echo "Put back exactly as it was."; '
            . 'else echo "Could not fully put the original container back - see the output above. '
            . 'The handover note has been left in place; try again from the stack menu."; fi; '
            . 'echo "'.STAXX_JOB_END.' $ec"';

    $shown = 'compose down && '.implode(' && ', array_map(
      fn($t) => 'docker rename '.$t['setaside'].' '.$t['original'], $state['targets']
    ));
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';
  @file_put_contents($log, '$ '.$shown."\n\n");
  @chmod($log, 0600);
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

  // The review lock is not consulted here either, for the same reason a
  // handover does not consult it: the gate is staxx_project_containers()
  // below being non-empty, and requiring the lock as well would put this
  // route out of reach the moment someone cleared it.
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

  // A stack with no review note is the common case. $heldPath and
  // $reviewPath stay '' and both the rename and its rollback skip
  // themselves.
  $reviewName = staxx_review_file($dir);
  $heldPath   = $reviewName !== '' ? $dir.'/.'.$reviewName : '';
  $reviewPath = $reviewName !== '' ? $dir.'/'.$reviewName : '';

  // Held aside rather than deleted up front, the same as a handover, so the
  // stack is never briefly unlocked while the job runs.
  if ($reviewPath !== '' && !@rename($reviewPath, $heldPath)) {
    $error = 'Could not set the review note aside, so nothing was started.';
    return '';
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
    if ($reviewPath !== '') @rename($heldPath, $reviewPath);
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
  @chmod($log, 0600);

  // $? is captured into $ec straight after the one real step, before the
  // rollback's own commands get a chance to overwrite it — the same reason
  // staxx_start_job() puts its STAXX_JOB_END check right after the chain.
  // The rm/mv pair only makes sense when there was a note to hold aside —
  // with none, both branches would act on an empty path, so the whole
  // if/else is left out rather than guarded step by step.
  $restoreNote = $heldPath !== ''
    ? 'if [ "$ec" -eq 0 ]; then rm -f '.escapeshellarg($heldPath).' 2>&1; '
    . 'else mv '.escapeshellarg($heldPath).' '.escapeshellarg($reviewPath).' 2>/dev/null; fi; '
    : '';
  $script = 'cd '.escapeshellarg($dir).' && '.$step.'; ec=$?; '
          . $restoreNote
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
  // Compared case-sensitively, unlike a compose filename above: the risk
  // there was a near-miss upload becoming a second file compose might
  // actually read. Here the record directory's real path always comes from
  // the constant itself, so a differently-cased name is just an ordinary
  // file that happens to look similar — nothing to protect against.
  if ($file === STAXX_RECORD_DIR) {
    $error = 'That name is reserved for this stack\'s own history, and cannot be used here.';
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
    // The history folder is bookkeeping, not a file anybody put there —
    // see STAXX_RECORD_DIR and staxx_stack_file().
    if ($name === STAXX_RECORD_DIR) continue;

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
  // A picture is never offered as editable text, even when it technically is
  // one: an SVG is plain text all the way down, so without this an icon
  // sitting in the stack's folder opens as a tab in the editor beside the
  // compose file, inviting an edit nobody wants to make there. It is still
  // listed, and still downloadable, like any other companion file.
  $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
  if (in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif'], true)) {
    return false;
  }

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
  if ($size === false) {
    // Unknown size is refused rather than let through: the whole point of
    // this cap is to stop a huge file being read into memory, and a
    // filesystem error here is no reason to skip that check.
    $error = 'Could not determine the size of "'.$file.'", so it was not read.';
    return null;
  }
  if ($size > STAXX_FILE_MAX) {
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

/* ---------------------------------------------------------------------
 * PLAN_76 Phase 2 — sorting a stack's files for export.
 *
 * staxx_export_sort() is a pure function over one stack's folder: every
 * file, with a verdict (redactable / read / refused) and a reason a person
 * can act on. It is deliberately built on staxx_list_files() rather than
 * its own scan of the directory, so the hidden record folder is left out
 * for the one reason it is ever left out anywhere in this file — because
 * that helper already excludes it.
 * --------------------------------------------------------------------- */

/**
 * Does this file look like key material? This cannot rest on
 * staxx_looks_text()'s binary check — a private key in the usual PEM format
 * is plain text and sails straight through it. So both ends are checked:
 * the name (which catches the ordinary case) and the first line's marker
 * (which catches the same key renamed to something innocuous). Either one
 * is enough to refuse; neither passing is only "not obviously a key", which
 * is what the "read" kind is for rather than a licence to trust the file.
 *
 * @return string a reason to refuse, or '' if this check finds nothing.
 */
function staxx_key_material_reason(string $path, string $name): string {
  static $extensions = ['key', 'pem', 'crt', 'cer', 'der', 'pfx', 'p12', 'jks',
                         'keystore', 'kdbx', 'asc', 'gpg', 'ppk', 'csr'];
  static $bareNames  = ['id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519',
                         'id_rsa.pub', 'id_dsa.pub', 'id_ecdsa.pub', 'id_ed25519.pub'];

  $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
  if (in_array($ext, $extensions, true) || in_array(strtolower($name), $bareNames, true)) {
    return '"'.$name.'" has the name of a key or certificate file, so it is never offered for export.';
  }

  // A small read, not the whole file — the marker that matters is always at
  // the very start. openssh-key-v1's magic is the one binary format here, so
  // it is looked for anywhere in the chunk rather than pinned to the first
  // line the way the two text markers are.
  $head = @file_get_contents($path, false, null, 0, 4096);
  if ($head !== false) {
    $firstLine = strtok($head, "\n");
    if ($firstLine === false) $firstLine = $head;
    if (str_starts_with($firstLine, '-----BEGIN')
        || str_starts_with($firstLine, 'PuTTY-User-Key-File')
        || strpos($head, 'openssh-key-v1') !== false) {
      return '"'.$name.'" starts with the marker of a private key, so it is never offered for export.';
    }
  }
  return '';
}

/**
 * Every x-unraid icon: value in the compose file that could plausibly be a
 * file — neither an http(s):// URL nor a Font Awesome glyph (fa-something),
 * since neither of those names anything on disk; see staxx_icon_resolve()'s
 * own checks for the same two shapes. Trimmed of quotes and whitespace. A
 * stack can carry more than one icon: line — one at the top level, one per
 * service — so every line is returned, not just the first.
 *
 * @return string[]
 */
function staxx_compose_icon_values(string $composeText): array {
  if ($composeText === '') return [];
  if (!preg_match_all('/^\s*icon:\s*["\']?([^"\'\r\n]*?)["\']?\s*$/mi', $composeText, $matches)) return [];
  $out = [];
  foreach ($matches[1] as $val) {
    $val = trim($val);
    if ($val === '') continue;
    if (preg_match('#^https?://#i', $val)) continue;
    if (preg_match('/^fa-[a-z0-9-]+$/i', $val)) continue;
    $out[] = $val;
  }
  return $out;
}

/**
 * Does the compose file actually name this companion file — through
 * env_file:, a configs:/secrets: "file:" source, a relative bind mount, or
 * x-unraid's own icon: value? A light text scan on purpose, not a second
 * compose parser: the model already exists elsewhere for anything that needs
 * the real structure, and this only has to answer one narrow question well
 * enough to inform a choice, never to make one.
 *
 * @return array{0:bool, 1:string} [needed, plain-English reason]
 */
function staxx_compose_names_file(string $composeText, string $filename): array {
  if ($composeText === '') return [false, ''];

  // Checked once over the whole file rather than inside the per-line loop
  // below, because an icon: value is a standalone key, not a marker that
  // introduces a following block of its own the way env_file: does.
  foreach (staxx_compose_icon_values($composeText) as $val) {
    $bareIcon = preg_replace('#^\.{1,2}/#', '', $val); // an optional leading ./ or ../
    if ($bareIcon === $filename) return [true, 'the compose file uses it as this stack\'s icon'];
  }

  $lines = preg_split('/\r\n|\r|\n/', $composeText);
  $q     = preg_quote($filename, '/');
  $bare  = '(?:\.\.?\/)?'.$q; // an optional leading ./ or ../

  foreach ($lines as $i => $line) {
    // env_file: as an inline scalar or single-item list on one line.
    if (preg_match('/env_file:\s*\[?\s*["\']?'.$bare.'["\']?\s*\]?\s*$/i', $line)) {
      return [true, 'the compose file loads it as an env_file'];
    }
    // env_file: as a multi-line list — walk the "- name" items under it.
    if (preg_match('/^\s*env_file:\s*$/i', $line)) {
      for ($j = $i + 1; $j < count($lines); $j++) {
        if (!preg_match('/^\s*-\s*["\']?([^"\'\s][^"\']*)["\']?\s*$/', $lines[$j], $m)) break;
        $item = trim($m[1]);
        if ($item === $filename || $item === './'.$filename || $item === '../'.$filename) {
          return [true, 'the compose file loads it as an env_file'];
        }
      }
    }
    // A configs:/secrets: entry names its source with a "file:" key —
    // that key has no other use in a compose file, so matching on it alone
    // is enough without tracking which top-level block it sits under.
    if (preg_match('/^\s*file:\s*["\']?'.$bare.'["\']?\s*$/i', $line)) {
      return [true, 'the compose file reads it as a config or secret source'];
    }
    // A relative bind mount: "- ./name:/target" or "- name:/target:ro".
    if (preg_match('/^\s*-\s*["\']?'.$bare.'["\']?:/', $line)) {
      return [true, 'the compose file mounts it into the container'];
    }
  }
  return [false, ''];
}

/**
 * Sort every file in a stack's folder into the four kinds PLAN_76 Phase 2
 * defines. The hidden record folder itself never appears here, because
 * staxx_list_files() has already left it out — except for one single
 * allowlisted exception appended at the very end; see the comment there.
 *
 * A refusal is settled first, for every file alike: a folder or a link
 * cannot travel as a single file, an oversized one cannot travel at all,
 * and key material is never offered whatever it is called. Only then are
 * the compose file and a .env called 'redactable' — their contents are
 * names and values, so they can be blanked field by field. What is left is
 * either ordinary text, which travels once it has been read, or something
 * that fails even the binary check, refused for the same reason a link is:
 * nothing here can vouch for it.
 *
 * @return array<int, array{name:string, kind:string, size:int, why:string,
 *                           needed:bool, needed_why:string, b64?:string}>|null
 */
function staxx_export_sort(string $rel, string &$error): ?array {
  $files = staxx_list_files($rel, $error);
  if ($files === null) return null;

  $dir         = staxx_stack_dir($rel);
  $composePath = staxx_find_compose_file($dir);
  $composeText = $composePath !== '' ? (string)@file_get_contents($composePath) : '';

  $out = [];
  foreach ($files as $f) {
    $path = $dir.'/'.$f['name'];
    [$needed, $neededWhy] = staxx_compose_names_file($composeText, $f['name']);
    if ($f['compose']) $needed = true; // the compose file always names itself

    // Refusals are settled before anything is called blankable, so a link
    // or an oversized file named ".env" — or a compose file that is itself a
    // link — is refused rather than quietly followed. Written the other way
    // round, the two kinds that can be blanked would have skipped every
    // refusal below, which is the one ordering mistake here that leaks a
    // file nobody chose.
    $refusal = '';
    if      ($f['link'])                  $refusal = '"'.$f['name'].'" is a link, so what it points to would leave rather than the file itself.';
    else if ($f['dir'])                   $refusal = '"'.$f['name'].'" is a folder, so it cannot travel as a single file.';
    else if ($f['size'] > STAXX_FILE_MAX) $refusal = '"'.$f['name'].'" is '.ceil($f['size'] / 1024).' KiB, over the '
                                                   . round(STAXX_FILE_MAX / 1024).' KiB limit for a file that leaves this way.';
    else                                  $refusal = staxx_key_material_reason($path, $f['name']);

    if ($refusal !== '') {
      $out[] = ['name' => $f['name'], 'kind' => 'refused', 'size' => $f['size'],
        'why' => $refusal, 'needed' => $needed, 'needed_why' => $neededWhy];
      continue;
    }

    if ($f['compose']) {
      $out[] = ['name' => $f['name'], 'kind' => 'redactable', 'size' => $f['size'],
        'why' => 'This is the stack\'s compose file, so its settings can be blanked one at a time.',
        'needed' => $needed, 'needed_why' => $neededWhy];
      continue;
    }
    if ($f['name'] === '.env') {
      $out[] = ['name' => $f['name'], 'kind' => 'redactable', 'size' => $f['size'],
        'why' => 'This holds names and values the same way the compose file does, '
               . 'so it can be blanked field by field.',
        'needed' => $needed, 'needed_why' => $neededWhy];
      continue;
    }
    if (!$f['text']) {
      $out[] = ['name' => $f['name'], 'kind' => 'refused', 'size' => $f['size'],
        'why' => '"'.$f['name'].'" looks like a binary file, so there is no safe way to check '
               . 'it for anything private before it leaves.',
        'needed' => $needed, 'needed_why' => $neededWhy];
      continue;
    }

    $out[] = ['name' => $f['name'], 'kind' => 'read', 'size' => $f['size'],
      'why' => 'This is a plain text file, so it can only leave once its contents have been shown.',
      'needed' => $needed, 'needed_why' => $neededWhy];
  }

  /* ---- the one allowlisted exception: the compose file's own icon -------
   *
   * The hidden record folder also holds history/ — full copies of every
   * past save, 54 of 114 of which hold a live password, secret or token on
   * this server today, which is precisely what exporting exists to strip —
   * and record.json, this installation's own rollback ledger, which a
   * rollback deliberately trusts only because it recorded it itself. Either
   * one leaving would either undo the redaction the export just performed,
   * or hand another machine a ledger entry it never earned.
   *
   * So this is an ALLOWLIST of exactly one file, not "the folder minus the
   * risky bits": the only thing ever taken out of the record folder is the
   * single picture the compose file's own x-unraid icon: names, found and
   * read here on the server — never a path the browser gets to ask for (see
   * staxx_stack_file(), which goes on refusing the record folder outright).
   * Anything else that ever lands in this folder in future stays home by
   * default; extending this to more of the folder's contents needs a new
   * conscious decision, not a wider glob.
   */
  foreach (staxx_compose_icon_values($composeText) as $val) {
    $bareIcon = preg_replace('#^\./#', '', $val); // only a single "./" — ".." never reaches here
    $prefix   = STAXX_RECORD_DIR.'/';
    if (strncmp($bareIcon, $prefix, strlen($prefix)) !== 0) continue;

    $inner = substr($bareIcon, strlen($prefix));
    if (!staxx_valid_filename($inner)) continue; // no slash, no "..", no history/ or record.json by shape

    $iconPath = $dir.'/'.STAXX_RECORD_DIR.'/'.$inner;
    if (is_link($iconPath) || !is_file($iconPath)) continue;
    $size = @filesize($iconPath);
    if ($size === false || $size > STAXX_FILE_MAX) continue;
    $body = @file_get_contents($iconPath);
    if ($body === false) continue;

    $out[] = ['name' => $bareIcon, 'kind' => 'icon', 'size' => (int)$size,
      'why' => 'This is the picture the compose file uses as this stack\'s icon.',
      'needed' => true, 'needed_why' => 'the compose file uses it as this stack\'s icon',
      'b64' => base64_encode($body)];
    break; // there is only ever one icon, however many icon: lines name it
  }

  return $out;
}

/* ---------------------------------------------------------------------
 * PLAN_76 Phase 5 — packing what leaves.
 * --------------------------------------------------------------------- */

/**
 * If $name is the one nested shape staxx_export_pack() accepts — ".staxx/"
 * plus a valid companion filename — return that inner filename; otherwise ''.
 * Kept as its own function so the check is worded identically everywhere it
 * is made, rather than three copies drifting apart over time.
 */
function staxx_export_pack_record_icon(string $name): string {
  $prefix = STAXX_RECORD_DIR.'/';
  if (strncmp($name, $prefix, strlen($prefix)) !== 0) return '';
  $inner = substr($name, strlen($prefix));
  return staxx_valid_filename($inner) ? $inner : '';
}

/**
 * Build a zip from name-and-contents pairs, and nothing else.
 *
 * $pairs is a list of ['name' => ..., 'content' => ...] — or, for the one
 * icon entry staxx_export_sort() ever hands back, ['name' => ..., 'b64' =>
 * ...], its raw bytes still base64 rather than run through a text decoder
 * that would corrupt anything that is not valid UTF-8 (most icons on a real
 * server are PNGs, not SVGs). A pair carries exactly one of the two —
 * action.php's own validation for "export-pack" already refuses one
 * carrying both or neither. That is the ONLY shape this function can read —
 * there is no parameter here that could carry a stack's folder, and nothing
 * inside calls anything that resolves one. This is the plan's strongest
 * rule, and it is enforced by shape rather than vigilance: the function is
 * not being careful not to look at the real stack, it has no way to,
 * whatever it is handed.
 *
 * $stackName is used only to name the zip for the person saving it — never to
 * find a folder, a file, or anything else on disk. The caller (action.php's
 * "export-pack") is the only place that ever puts a real stack's path into
 * it, and even there it never reaches this function's own filesystem work.
 *
 * Every name is checked by staxx_valid_filename(), the same rule a companion
 * file is written under, with two deliberate exceptions. First, that function
 * also refuses the compose filenames themselves, because a second file by
 * that name would collide with the real compose file already on disk. There
 * is no "already on disk" here — this zip is built from nothing — and the
 * compose file is exactly what belongs in an export, so a name is accepted
 * whenever it is either a valid companion name or one of the compose
 * filenames. Second, a name of exactly ".staxx/" followed by a valid
 * companion name is also accepted — see staxx_export_pack_record_icon() —
 * because that is the one shape staxx_export_sort()'s icon allowlist ever
 * hands back. Nothing else may nest: staxx_valid_filename() forbids both a
 * slash and "..", so the inner half can only ever name a direct child of the
 * one ".staxx" subfolder this function creates for it, never anything above
 * or beside the temporary folder these files are written into.
 *
 * Built in a private folder of its own under STAXX_CFILE_TMP, using the same
 * `zip` shell command staxx_archive_stack() already uses — there is only one
 * way this plugin makes a zip. That folder is removed whether packing
 * succeeded or not.
 *
 * @param array<int, array{name:string, content?:string, b64?:string}> $pairs
 * @return string the zip's raw bytes, or '' plus $error.
 */
function staxx_export_pack(array $pairs, string $stackName, string &$error): string {
  $error = '';

  if (count($pairs) === 0) { $error = 'There is nothing to export.'; return ''; }
  if (count($pairs) > STAXX_EXPORT_FILES_MAX) {
    $error = 'That is '.count($pairs).' files, over the '.STAXX_EXPORT_FILES_MAX
           . '-file limit for one export.';
    return '';
  }

  $total = 0;
  $seen  = [];
  foreach ($pairs as $p) {
    $name = is_string($p['name'] ?? null) ? $p['name'] : '';

    if (!staxx_valid_filename($name) && !in_array(strtolower($name), STAXX_COMPOSE_FILENAMES, true)
        && staxx_export_pack_record_icon($name) === '') {
      $error = '"'.$name.'" is not a name this can export.';
      return '';
    }
    if (isset($seen[strtolower($name)])) {
      $error = '"'.$name.'" was given twice.';
      return '';
    }
    $seen[strtolower($name)] = true;

    // A b64 pair (the icon — see staxx_export_pack_record_icon()) is counted
    // by its DECODED length, not the base64 text's own length, or the size
    // cap this accounts for would be wrong by roughly a third.
    if (is_string($p['b64'] ?? null)) {
      $decoded = base64_decode($p['b64'], true);
      if ($decoded === false) {
        $error = '"'.$name.'" did not arrive intact.';
        return '';
      }
      $total += strlen($decoded);
    } else {
      $total += strlen(is_string($p['content'] ?? null) ? $p['content'] : '');
    }
    if ($total > STAXX_EXPORT_MAX) {
      $error = 'That export is '.round($total / 1024).' KiB, over the '
             . round(STAXX_EXPORT_MAX / 1024).' KiB limit for one download.';
      return '';
    }
  }

  if (!staxx_private_dir(STAXX_CFILE_TMP)) {
    $error = 'Could not prepare a place to build the export.';
    return '';
  }

  $work = STAXX_CFILE_TMP.'/export-'.bin2hex(random_bytes(8));
  if (!@mkdir($work, 0700, true)) {
    $error = 'Could not prepare a place to build the export.';
    return '';
  }
  $real = @realpath($work);
  if ($real === false) {
    $error = 'Could not prepare a place to build the export.';
    return '';
  }

  $zipBytes = '';
  try {
    foreach ($pairs as $p) {
      $name = (string)$p['name'];

      // The icon arrives as base64 rather than text — decoded here, not in
      // the browser or the endpoint, so the bytes are never passed through a
      // text decoder that would corrupt anything that is not valid UTF-8
      // (most icons on a real server are PNGs, not SVGs).
      if (is_string($p['b64'] ?? null)) {
        $bytes = base64_decode($p['b64'], true);
        if ($bytes === false) {
          $error = '"'.$name.'" did not arrive intact.';
          return '';
        }
      } else {
        $bytes = is_string($p['content'] ?? null) ? $p['content'] : '';
      }

      // The only name shape with a directory in it — create that one
      // subfolder on first use. is_dir() after a failed mkdir() covers the
      // second such file in the same export finding it already there.
      if (staxx_export_pack_record_icon($name) !== ''
          && !@mkdir($real.'/'.STAXX_RECORD_DIR, 0700, true) && !is_dir($real.'/'.STAXX_RECORD_DIR)) {
        $error = 'Could not prepare a place to build the export.';
        return '';
      }
      if (@file_put_contents($real.'/'.$name, $bytes) === false) {
        $error = 'Could not write "'.$name.'" while building the export.';
        return '';
      }
    }

    // A version marker, written by this function alone rather than accepted
    // from the browser — a bundle cannot be produced without one. Once
    // bundles exist in the wild, an importer needs to know what shape it has
    // been handed; without this, a bundle written today could never say what
    // it is, and the importer would be left sniffing at its contents. It
    // carries no machine name, user name, path or timestamp — the covering
    // note already inside the compose file is the anonymous record of when
    // and where from, and this must stay just as anonymous. Written last and
    // unconditionally, overwriting rather than refusing on the one-in-never
    // chance a pair already claimed this exact name — a stack cannot
    // actually supply a file here (the icon is the only nested entry
    // staxx_export_sort() ever hands back, and it is named for the picture,
    // not this marker), and the format identity matters more than a name
    // nobody real would collide with.
    if (!@mkdir($real.'/'.STAXX_RECORD_DIR, 0700, true) && !is_dir($real.'/'.STAXX_RECORD_DIR)) {
      $error = 'Could not prepare a place to build the export.';
      return '';
    }
    $marker = json_encode(['format' => 'staxx-bundle', 'version' => 1], JSON_PRETTY_PRINT);
    if (@file_put_contents($real.'/'.STAXX_RECORD_DIR.'/bundle.json', $marker) === false) {
      $error = 'Could not write the bundle marker while building the export.';
      return '';
    }

    // $stackName's only use anywhere in this function: a label on the
    // temporary zip file itself, never a path segment resolved against
    // anything real. Scrubbed to a safe character set the same way
    // staxx_archive_name() scrubs a stack's path for its own zip name.
    $label = $stackName !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '-', str_replace('/', '-', $stackName)) : 'export';
    $zipPath = STAXX_CFILE_TMP.'/'.$label.'-'.bin2hex(random_bytes(4)).'.zip';
    $zipCode = 1;
    $zipOut  = staxx_sh(
      'cd '.escapeshellarg($real).' && zip -r -y -q '.escapeshellarg($zipPath).' . 2>&1',
      60,
      $zipCode
    );
    if ($zipCode !== 0 || !is_file($zipPath)) {
      @unlink($zipPath);
      $error = 'Could not build the export.'.($zipOut !== '' ? "\n\n".trim($zipOut) : '');
      return '';
    }

    $bytes = @file_get_contents($zipPath);
    @unlink($zipPath);
    if ($bytes === false) {
      $error = 'Could not read back the export once it was built.';
      return '';
    }
    $zipBytes = $bytes;
    return $zipBytes;
  } finally {
    // Removed on every path out of this function, success or refusal alike —
    // nothing this builds is worth keeping once the reply is on its way.
    staxx_rmtree($real, $real);
  }
}

/* ------------------------------------------------------- PLAN_101 — import --
 *
 * Reading a bundle back is the mirror image of staxx_export_pack() above,
 * with the direction of trust reversed. Exporting, StaXX wrote every byte in
 * the zip. Importing, the zip is a claim from somewhere else, and every name
 * inside it is untrusted until this has checked it — so nothing is extracted
 * until every name has passed, and what actually lands is checked again from
 * scratch rather than trusting the listing to have predicted it.
 */

/**
 * Walk an already-extracted bundle folder, refusing a symlink, a directory
 * other than the one record folder, or anything that resolves outside
 * $root. Returns the plain file paths found, relative to $root with forward
 * slashes, or null with $error set.
 *
 * Kept separate from staxx_bundle_read() only because it recurses one level
 * into the record folder — nothing here is reused anywhere else.
 *
 * @return string[]|null
 */
function staxx_bundle_walk(string $dir, string $root, string &$error): ?array {
  $entries = @scandir($dir);
  if ($entries === false) {
    $error = 'That bundle could not be read back after unpacking.';
    return null;
  }

  $out = [];
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = $dir.'/'.$entry;

    // A link is refused before anything resolves it, same reasoning as
    // staxx_rmtree(): resolving first would either follow it somewhere real
    // (is_dir()) or report "outside the tree" for a target that is outside
    // by definition (realpath()) — neither is the right refusal to give.
    if (is_link($path)) {
      $error = 'This bundle contains a link, which is not something a bundle StaXX made would ever hold.';
      return null;
    }

    $rel = substr($path, strlen($root) + 1);

    if (is_dir($path)) {
      if ($rel !== rtrim(STAXX_RECORD_DIR, '/')) {
        $error = '"'.$rel.'" is a folder this bundle should not contain.';
        return null;
      }
      $real = @realpath($path);
      if ($real === false || ($real !== $root && strpos($real, $root.'/') !== 0)) {
        $error = 'This bundle tries to reach outside itself.';
        return null;
      }
      $sub = staxx_bundle_walk($path, $root, $error);
      if ($sub === null) return null;
      $out = array_merge($out, $sub);
      continue;
    }

    $real = @realpath($path);
    if ($real === false || ($real !== $root && strpos($real, $root.'/') !== 0)) {
      $error = 'This bundle tries to reach outside itself.';
      return null;
    }
    $out[] = $rel;
  }
  return $out;
}

/**
 * Validate and unpack an uploaded ".staxx" bundle. Writes nothing outside a
 * throwaway folder under STAXX_CFILE_TMP, which is removed on every path out
 * of this function, refusal included.
 *
 * The order below IS the safety, in the same sense staxx_rmtree()'s own
 * docblock means it: every entry's name is checked before anything is
 * extracted, so a crafted zip never gets as far as having a single byte of
 * itself written to disk; only then is it extracted; and only then is what
 * actually landed checked again from scratch, belt and braces against unzip
 * behaving differently from its own listing.
 *
 * @return array{compose:array{name:string,text:string},
 *               files:array<int,array{name:string,text:string,size:int}>,
 *               icon:?array{name:string,bytes:string,size:int},
 *               version:int, marked:bool}|null
 */
function staxx_bundle_read(string $bytes, string &$error): ?array {
  $error = '';

  if ($bytes === '') { $error = 'That bundle is empty.'; return null; }
  if (strlen($bytes) > STAXX_BUNDLE_MAX) {
    // Rounded UP, and in KiB the way staxx_export_pack() words its own caps:
    // a bundle one byte over 4 MiB rounds to "4 MiB, over the 4 MiB limit",
    // which reads like the code is wrong rather than the file being too big.
    $error = 'That bundle is '.ceil(strlen($bytes) / 1024).' KiB, over the '
           . round(STAXX_BUNDLE_MAX / 1024).' KiB limit for one bundle.';
    return null;
  }

  if (!staxx_private_dir(STAXX_CFILE_TMP)) {
    $error = 'Could not prepare a place to read the bundle.';
    return null;
  }
  $work = STAXX_CFILE_TMP.'/bundle-'.bin2hex(random_bytes(8));
  if (!@mkdir($work, 0700, true)) {
    $error = 'Could not prepare a place to read the bundle.';
    return null;
  }
  $real = @realpath($work);
  if ($real === false) {
    $error = 'Could not prepare a place to read the bundle.';
    return null;
  }

  try {
    $zipPath = $real.'/bundle.zip';
    if (@file_put_contents($zipPath, $bytes) === false) {
      $error = 'Could not write the bundle to a scratch file.';
      return null;
    }

    // Step 1: list the entries without extracting anything.
    $listCode = 1;
    $listOut  = staxx_sh('unzip -Z1 '.escapeshellarg($zipPath).' 2>&1', 20, $listCode);
    if ($listCode !== 0) {
      $error = 'That file is not a bundle StaXX can read.';
      return null;
    }
    $names = array_values(array_filter(explode("\n", $listOut), fn($n) => $n !== ''));
    if ($names === []) {
      $error = 'That bundle is empty.';
      return null;
    }
    if (count($names) > STAXX_EXPORT_FILES_MAX) {
      $error = 'That bundle holds '.count($names).' entries, over the '.STAXX_EXPORT_FILES_MAX
             . '-entry limit for one bundle.';
      return null;
    }

    // Step 2: every name checked before anything is extracted, and the WHOLE
    // bundle refused on the first bad one. An unrecognised entry means this
    // is not a bundle StaXX made, and guessing at what to do with it is
    // exactly how something unexpected ends up written to disk.
    $composeName    = '';
    $fileNames      = [];
    $iconName       = '';
    $markerSeen     = false;
    $recordDirEntry = STAXX_RECORD_DIR.'/';

    foreach ($names as $entry) {
      if ($entry[0] === '/' || $entry[0] === '\\') {
        $error = '"'.$entry.'" is not a name a bundle StaXX made would ever hold.';
        return null;
      }
      if (strpos($entry, '\\') !== false) {
        $error = '"'.$entry.'" holds a backslash, which is not a name a bundle StaXX made would ever hold.';
        return null;
      }
      if (strpos($entry, '..') !== false) {
        $error = '"'.$entry.'" tries to step outside the bundle, so the whole bundle is refused.';
        return null;
      }
      if (preg_match('/^[A-Za-z]:/', $entry)) {
        $error = '"'.$entry.'" names a drive, which is not a name a bundle StaXX made would ever hold.';
        return null;
      }
      if (substr($entry, -1) === '/') {
        // A bare directory entry is ordinary in a zip — the only one a
        // bundle StaXX made ever holds is the record folder itself.
        if ($entry === $recordDirEntry) continue;
        $error = '"'.$entry.'" is a folder this bundle should not contain.';
        return null;
      }

      if (in_array(strtolower($entry), STAXX_COMPOSE_FILENAMES, true)) {
        if ($composeName !== '') {
          $error = 'This bundle holds more than one compose file, so it is not clear which one to import.';
          return null;
        }
        $composeName = $entry;
        continue;
      }
      if ($entry === $recordDirEntry.'bundle.json') {
        $markerSeen = true;
        continue;
      }
      // The record folder's ONE permitted entry beyond the marker is the
      // picture, and it is recognised by its extension as well as its shape.
      // Without the extension test a flat ".staxx/record.json" reads as a
      // candidate picture and is only stopped later, when its bytes turn out
      // not to be any picture format — which works, but leaves the allowlist
      // resting on a check two steps away. A planted record file has to be
      // refused by its NAME.
      if (staxx_export_pack_record_icon($entry) !== ''
          && in_array(strtolower((string)pathinfo($entry, PATHINFO_EXTENSION)), STAXX_ICON_EXTS, true)) {
        if ($iconName !== '') {
          $error = 'This bundle holds more than one picture, so it is not clear which one is this stack\'s icon.';
          return null;
        }
        $iconName = $entry;
        continue;
      }
      if (staxx_valid_filename($entry)) {
        $fileNames[] = $entry;
        continue;
      }

      $error = '"'.$entry.'" is not a name this can import.';
      return null;
    }

    if ($composeName === '') {
      $error = 'This bundle has no compose file, so there is nothing to import.';
      return null;
    }

    // Step 3: extract, only now that every name has passed.
    $extractDir = $real.'/x';
    if (!@mkdir($extractDir, 0700, true)) {
      $error = 'Could not prepare a place to unpack the bundle.';
      return null;
    }
    $unzipCode = 1;
    staxx_sh('unzip -qq -o '.escapeshellarg($zipPath).' -d '.escapeshellarg($extractDir).' 2>&1', 30, $unzipCode);
    if ($unzipCode !== 0) {
      $error = 'That bundle could not be unpacked.';
      return null;
    }

    // Step 4: check what actually landed, rather than trusting the listing
    // above to have predicted it.
    $extractReal = @realpath($extractDir);
    if ($extractReal === false) {
      $error = 'That bundle could not be unpacked.';
      return null;
    }
    $expected = array_merge(
      [$composeName],
      $fileNames,
      $iconName !== '' ? [$iconName] : [],
      $markerSeen ? [$recordDirEntry.'bundle.json'] : []
    );
    $landed = staxx_bundle_walk($extractReal, $extractReal, $error);
    if ($landed === null) return null;
    foreach ($landed as $foundPath) {
      if (!in_array($foundPath, $expected, true)) {
        $error = '"'.$foundPath.'" was not in the bundle\'s own listing, so the bundle is refused.';
        return null;
      }
    }

    // Step 5: read. The compose file is validated exactly the way
    // staxx_save_stack() validates one before writing it — $extractReal is
    // passed as the project directory so a relative env_file: can resolve
    // against the companion files that were just unpacked alongside it.
    $composePath = $extractReal.'/'.$composeName;
    if (!is_file($composePath)) {
      $error = 'This bundle\'s compose file could not be found after unpacking.';
      return null;
    }
    $composeText = (string)@file_get_contents($composePath);
    $validateError = '';
    $warnings = null;
    if (!staxx_validate_compose($composeText, $validateError, $extractReal, $warnings)) {
      $error = 'This bundle\'s compose file is not valid: '.$validateError;
      return null;
    }

    $files = [];
    foreach ($fileNames as $fn) {
      $fp = $extractReal.'/'.$fn;
      if (!is_file($fp)) {
        $error = '"'.$fn.'" could not be found after unpacking.';
        return null;
      }
      $size = (int)@filesize($fp);
      if ($size > STAXX_FILE_MAX) {
        $error = '"'.$fn.'" is '.ceil($size / 1024).' KiB, over the '.round(STAXX_FILE_MAX / 1024)
               . ' KiB limit for a companion file.';
        return null;
      }
      // No bundle StaXX made ever carries a binary companion file — export
      // refuses to pack one in the first place — so one turning up here
      // means this bundle was not made by StaXX, whatever it claims.
      if (!staxx_looks_text($fp)) {
        $error = '"'.$fn.'" looks like a binary file, so it is refused rather than trusted.';
        return null;
      }
      $files[] = ['name' => $fn, 'text' => (string)@file_get_contents($fp), 'size' => $size];
    }

    $icon = null;
    if ($iconName !== '') {
      $ip = $extractReal.'/'.$iconName;
      if (!is_file($ip)) {
        $error = 'This bundle\'s picture could not be found after unpacking.';
        return null;
      }
      $iconSize = (int)@filesize($ip);
      if ($iconSize > STAXX_FILE_MAX) {
        $error = 'This bundle\'s picture is over the '.round(STAXX_FILE_MAX / 1024).' KiB limit.';
        return null;
      }
      $iconBody = (string)@file_get_contents($ip);
      $ext      = strtolower((string)pathinfo($iconName, PATHINFO_EXTENSION));
      if (!staxx_icon_is_picture($ext, $iconBody)) {
        $error = 'This bundle\'s picture is not actually a picture.';
        return null;
      }
      $icon = ['name' => basename($iconName), 'bytes' => $iconBody, 'size' => $iconSize];
    }

    // Step 6: the marker. Absent is accepted as version 1 — the only bundles
    // without one predate the marker existing at all.
    $version = 1;
    $marked  = false;
    if ($markerSeen) {
      $markerRaw  = (string)@file_get_contents($extractReal.'/'.STAXX_RECORD_DIR.'/bundle.json');
      $markerData = @json_decode($markerRaw, true);
      if (!is_array($markerData) || ($markerData['format'] ?? '') !== 'staxx-bundle'
          || !is_int($markerData['version'] ?? null)) {
        $error = 'This bundle\'s marker file is not one StaXX wrote, so the bundle is refused.';
        return null;
      }
      $version = (int)$markerData['version'];
      if ($version > 1) {
        $error = 'This bundle was made by a newer version of StaXX than the one on this server.';
        return null;
      }
      $marked = true;
    }

    return [
      'compose' => ['name' => $composeName, 'text' => $composeText],
      'files'   => $files,
      'icon'    => $icon,
      'version' => $version,
      'marked'  => $marked,
    ];
  } finally {
    // Removed on every path out, success or refusal alike — nothing this
    // reads is worth keeping once the reply is on its way.
    staxx_rmtree($real, $real);
  }
}

/**
 * How many values in a bundle's compose file still need filling in.
 *
 * Comment lines are skipped: the covering note an export writes names the
 * placeholder twice while explaining it, so counting the whole file reports
 * two more outstanding values than the file actually has.
 */
function staxx_bundle_placeholders(string $composeText): int {
  $n = 0;
  foreach (explode("
", $composeText) as $line) {
    if (substr(ltrim($line), 0, 1) === '#') continue;
    $n += substr_count($line, STAXX_PLACEHOLDER);
  }
  return $n;
}

/**
 * Create a new stack from a bundle staxx_bundle_read() already validated.
 * Never merges into or overwrites an existing stack — staxx_create_refusal()
 * is the same gate "Add stack" uses, called the same way it always refuses a
 * name already in use.
 *
 * Only ever writes the compose file (through staxx_save_stack(), so it gets
 * exactly the same validation and history capture an ordinary save gets),
 * the companion files (through staxx_write_file()) and the picture (through
 * staxx_icon_write(), straight into the new stack's own record folder).
 * The bundle marker is never written — it describes the bundle, not the
 * stack — and nothing else ever reaches the record folder, by allowlist
 * rather than by filter: a crafted bundle must not be able to plant a false
 * history or a rollback record naming an image this machine has never run.
 *
 * The stack is created stopped, full stop. This never leans on the
 * REPLACE-ME start guard — it simply never starts anything.
 *
 * A failure part-way through is reported plainly, saying what did land; it
 * never attempts a rollback, which could delete more than this call created.
 */
function staxx_bundle_write(array $bundle, string $rel, string &$error): bool {
  $error = '';

  if (!staxx_valid_path($rel)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return false;
  }
  $refusal = staxx_create_refusal($rel, false);
  if ($refusal !== '') { $error = $refusal; return false; }

  $composeText = (string)($bundle['compose']['text'] ?? '');
  $saveError   = '';
  if (!staxx_save_stack($rel, $composeText, $saveError)) {
    $error = 'The compose file could not be written: '.$saveError;
    return false;
  }

  foreach ((array)($bundle['files'] ?? []) as $f) {
    $fname     = (string)($f['name'] ?? '');
    $ftext     = (string)($f['text'] ?? '');
    $fileError = '';
    if (!staxx_write_file($rel, $fname, $ftext, true, $fileError)) {
      $error = 'The stack was created, but "'.$fname.'" could not be written: '.$fileError;
      return false;
    }
  }

  $icon = $bundle['icon'] ?? null;
  if (is_array($icon)) {
    $iname  = (string)($icon['name'] ?? '');
    $ibytes = (string)($icon['bytes'] ?? '');
    // Checked again here rather than trusted from the caller: this function
    // is what turns a name into a path, so it is where the name has to be
    // proved safe, whatever validated it upstream.
    if (!staxx_valid_filename($iname)) {
      $error = 'The stack was created, but its picture is not named something this can write.';
      return false;
    }
    $target = staxx_stack_dir($rel).'/'.STAXX_RECORD_DIR.'/'.$iname;
    if (!staxx_icon_write($target, $ibytes)) {
      $error = 'The stack was created, but its picture could not be written.';
      return false;
    }
  }

  return true;
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

  // The compose file itself never reaches here — staxx_valid_filename()
  // refuses those four names outright, so the editor cannot open it and the
  // form's own save is the only door to it. What does reach here is the
  // override, which is compose configuration every bit as much as the main
  // file: it is authored by hand, saved straight over the top, and had no
  // way back before this. Anything else in the folder is a companion file
  // and is not this stack's compose history.
  $mainPath = staxx_find_compose_file(dirname($path));
  if ($mainPath !== ''
      && strcasecmp($file, staxx_expected_override_basename($mainPath)) === 0) {
    $recordNote = '';
    if (!staxx_record_capture($rel, $file, $recordNote) && $recordNote !== '') {
      error_log('StaXX: history not kept for '.$rel.'/'.$file.': '.$recordNote);
    }
  }

  // Pid-suffixed, not a fixed name: two concurrent saves of the same
  // companion file would otherwise share one temp file, and whichever
  // rename() lost the race would report an error for content that had
  // already landed under the other save's name.
  $tmp = $path.'.'.getmypid().'.staxx-tmp';
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

  // The boot-drive shelf copy (PLAN_103) only ever mirrors the compose file
  // and its override — an ordinary companion file (a .env, a certificate)
  // is not the stack's definition and never belongs on it. $mainPath and the
  // override-name check above already establish which case this is; nothing
  // further is copied for any other file this function writes.
  if ($mainPath !== ''
      && strcasecmp($file, staxx_expected_override_basename($mainPath)) === 0) {
    $bootNote = '';
    if (!staxx_boot_copy_stack($rel, $bootNote) && $bootNote !== '') {
      error_log('StaXX: boot copy not written for '.$rel.': '.$bootNote);
    }
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

  // Whether this is the override, checked before it is gone — deleting it
  // is a change to the stack's own definition, so the shelf copy (PLAN_103)
  // has to lose it too, the same as staxx_write_file() gives it one.
  $mainPath = staxx_find_compose_file(dirname($path));
  $isOverride = $mainPath !== ''
             && strcasecmp($file, staxx_expected_override_basename($mainPath)) === 0;

  if (is_link($path)) {
    if (!@unlink($path)) { $error = 'Could not delete "'.$file.'".'; return false; }
    if ($isOverride) staxx_boot_sync_after_delete($rel);
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
  if ($isOverride) staxx_boot_sync_after_delete($rel);
  return true;
}

/**
 * Re-run the shelf copy after an override is deleted, so PLAN_103's boot
 * copy drops the override it no longer needs to hold rather than going on
 * showing one that was just removed. staxx_boot_copy_stack() already skips
 * writing an override that is no longer on disk and unlinks a stale one
 * left over from an earlier copy — this just gives it the same chance
 * staxx_write_file() and staxx_save_stack() get, without a second version
 * of that logic here. A failure is only ever logged, same as every other
 * boot-copy call site — the delete this follows has already succeeded.
 */
function staxx_boot_sync_after_delete(string $rel): void {
  $bootNote = '';
  if (!staxx_boot_copy_stack($rel, $bootNote) && $bootNote !== '') {
    error_log('StaXX: boot copy not updated for '.$rel.': '.$bootNote);
  }
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

  // The shelf copy (PLAN_103) is keyed by the same rel path as the store, so
  // a rename moves it there too: drop the copy filed under the old name and
  // write one under the new — never left both, and never overwrite either
  // silently. Both are logged-only failures; the rename on disk has already
  // succeeded and nothing here may undo it.
  staxx_boot_remove_stack($rel);
  $bootNote = '';
  if (!staxx_boot_copy_stack($newRel, $bootNote) && $bootNote !== '') {
    error_log('StaXX: boot copy not written for '.$newRel.': '.$bootNote);
  }

  staxx_scan_stacks_reset(); // moved the directory; see the function's own comment
  return $newRel;
}

/* -------------------------------------------------------------- run jobs -- */

/**
 * Compose commands this plugin is willing to run, and how.
 *
 * An allowlist rather than anything assembled from user input: the verb comes
 * from a button, and only these nine exist.
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
 *   recreate  is the separate, honest verb for when the rebuild IS what you
 *             want, at either scope: `up -d --force-recreate`. It exists so
 *             the choice above is never quietly overridden — Restart keeps
 *             every guarantee described above, and this is the button you
 *             press instead when you actually want the logs gone.
 *
 *   remove    exists only at service scope; `down` already IS the stack-scope
 *             version of removing containers, so a second stack-scope entry
 *             would just be `down` under another name.
 *
 *   pull      fetches the new image onto disk and leaves everything running
 *             on what it already has — nothing about a live container
 *             changes. On its own, at either scope.
 *
 *   update    fetches the new image AND rebuilds the container on it: two
 *             commands run in sequence, `pull` then `up -d`, at both scopes.
 *             The pull half only fetches the new image onto disk — it does
 *             not touch the running container, which keeps using whatever
 *             image ID it already started with. `up -d` is the step that
 *             notices the service's tag now resolves to a different image ID
 *             and recreates the container on it, which is the only reason it
 *             is here at all: without it, "update" would just be `pull`
 *             wearing a more promising name.
 *
 * `config` has no service form because nothing in the menu ever asks for one
 * — "resolved settings" is a whole-file question.
 */
function staxx_job_verbs(): array {
  return [
    'up'       => ['args' => 'up -d --remove-orphans', 'svc' => 'up -d',             'label' => 'Start'],
    'down'     => ['args' => 'down',                   'svc' => 'stop',              'label' => 'Stop'],
    'restart'  => ['args' => ['up -d --remove-orphans', 'restart'],
                   'svc'  => ['up -d', 'restart'],                                   'label' => 'Restart'],
    'recreate' => ['args' => 'up -d --force-recreate --remove-orphans',
                   'svc'  => 'up -d --force-recreate',                              'label' => 'Recreate'],
    'pull'     => ['args' => 'pull',                   'svc' => 'pull',              'label' => 'Pull images'],
    'logs'     => ['args' => 'logs --tail 200',        'svc' => 'logs --tail 200',   'label' => 'Logs'],
    'config'   => ['args' => 'config',                                               'label' => 'Resolved settings'],
    'remove'   => [                                    'svc' => 'rm --stop --force', 'label' => 'Remove container'],
    'update'   => ['args' => ['pull', 'up -d --remove-orphans'],
                   'svc'  => ['pull', 'up -d'],                                      'label' => 'Update'],
    'rebuild'  => ['args' => ['build --pull', 'up -d --remove-orphans'],
                   'svc'  => ['build --pull', 'up -d'],                              'label' => 'Rebuild'],
  ];
}

/**
 * Which settings in a compose file are still waiting to be filled in.
 *
 * A plain text search for STAXX_PLACEHOLDER, then a line-by-line reading of
 * the file to say WHICH setting each occurrence sits on — a raw grep for the
 * token tells nobody anything they can act on.
 *
 * This deliberately does not go through staxx_compose_meta(): that shells out
 * to `compose config`, and a placeholder sitting in a slot compose expects to
 * parse as a number or a mapping (a port, for instance) can make the whole
 * file fail to resolve, which would report nothing at all rather than the one
 * thing this function exists to report. A light line-scan of the raw text,
 * tracking nesting by indentation the way the file is actually written, reads
 * every case compose itself would refuse.
 *
 * Only the main file is read — an override reintroducing the placeholder in
 * a value the main file already fills is a corner case this stage does not
 * chase, and staxx_compose_files() is not consulted here.
 *
 * @return string[] human-readable descriptions, in the order found, deduplicated
 */
function staxx_placeholders(string $file): array {
  if ($file === '') return [];
  $text = @file_get_contents($file);
  if ($text === false || $text === '') return [];
  if (strpos($text, STAXX_PLACEHOLDER) === false) return [];

  // A short table of the enclosing key names worth a friendlier name than
  // their own, for the case where the line itself carries no name of its own
  // (a bare "- REPLACE-ME" list entry rather than "KEY=REPLACE-ME" or
  // "key: REPLACE-ME").
  $generic = [
    'ports'    => 'a port',
    'expose'   => 'a port',
    'volumes'  => 'a folder path',
    'networks' => 'a network setting',
    'secrets'  => 'a secret',
    'configs'  => 'a value',
  ];

  $describe = function (string $key) : string {
    $words = strtolower(str_replace(['_', '-'], ' ', $key));
    $words = trim(preg_replace('/\s+/', ' ', $words));
    return $words === '' ? 'a value' : 'the '.$words;
  };

  $found  = [];   // description => true, so insertion order survives dedup
  $stack  = [];   // [[indent, key], ...] — the ancestor chain, most specific last
  $currentService = '';
  $serviceIndent  = null;

  foreach (explode("\n", $text) as $line) {
    $trimmed = ltrim($line, " \t");
    if ($trimmed === '' || $trimmed[0] === '#') continue;
    $indent = strlen($line) - strlen($trimmed);

    // A leading "- " (a sequence item) is read one level in, so
    // "- key: value" tracks the same as "key: value" a step deeper.
    $body = $trimmed;
    if (substr($body, 0, 2) === '- ') { $body = substr($body, 2); $indent += 2; }

    $key = null;
    if (preg_match('/^([A-Za-z0-9_.\-]+)\s*:(.*)$/', $body, $m)) {
      $key   = $m[1];
      $value = $m[2];

      while ($stack && $stack[count($stack) - 1][0] >= $indent) array_pop($stack);
      $parent = $stack ? $stack[count($stack) - 1][1] : '';
      if ($parent === 'services') { $currentService = $key; $serviceIndent = $indent; }
      elseif ($serviceIndent !== null && $indent <= $serviceIndent) {
        $currentService = ''; $serviceIndent = null;
      }
      $stack[] = [$indent, $key];

      if (strpos($value, STAXX_PLACEHOLDER) === false) continue;
      $desc = $describe($key);
    } else {
      if (strpos($body, STAXX_PLACEHOLDER) === false) continue;
      // A bare list entry. "NAME=REPLACE-ME" (an environment-style line)
      // names itself; otherwise fall back to whichever key this list sits
      // under (e.g. "ports"), or "a value" if even that is unclear.
      if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=/', $body, $m2)) {
        $desc = $describe($m2[1]);
      } else {
        while ($stack && $stack[count($stack) - 1][0] >= $indent) array_pop($stack);
        $parent = $stack ? $stack[count($stack) - 1][1] : '';
        $desc = $generic[$parent] ?? 'a value';
      }
    }

    if ($currentService !== '') $desc .= ' (in service '.$currentService.')';
    $found[$desc] = true;
  }

  return array_keys($found);
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
           . STAXX_REVIEW_FILE . ', then choose "Take over and start" or "Clear the lock only"'
           . ' before starting it.';
    return '';
  }

  // A container Docker knows under a name this stack pins, and belonging to
  // no compose project, is a template's own container: compose cannot create
  // ours beside it, so `up` fails on Docker's raw "name already in use" and
  // says nothing about the way out. Read off the verb table rather than a
  // list of verb names, so a verb added later that brings containers up is
  // covered without anyone remembering to come back here.
  //
  // Whole-stack scope only. A single service can only ever collide with this
  // stack's own container, which carries this project's compose label and so
  // is never among the holders below.
  $brings = $verbs[$verb]['args'] ?? '';
  $brings = is_array($brings) ? $brings : [$brings];
  $startsSomething = false;
  foreach ($brings as $step) if (strpos($step, 'up ') === 0) $startsSomething = true;

  if ($service === '' && $startsSomething) {
    $holders = array_map(fn($t) => $t['name'], staxx_foreign_holders($name));
    if ($holders) {
      $error = (count($holders) === 1
                 ? 'The container '.$holders[0].' is'
                 : 'The containers '.implode(' and ', $holders).' are')
             . ' already using a name this stack needs, so starting it would fail. '
             . 'Choose "Take over and start" from this stack\'s menu instead — it sets '
             . 'that container aside and starts this stack in its place.';
      return '';
    }
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  // Pick the scope-appropriate argument string, and fail loudly rather than
  // silently falling back to the other scope — running the wrong one of
  // these against the wrong target is exactly the kind of mistake this
  // allowlist exists to make impossible. Deliberately ahead of the compose
  // file lookup below: it depends on nothing but the verb table itself, so a
  // request for the wrong scope is refused for that reason even when the
  // stack does not exist on disk either — which is what lets
  // tests/server/console.php prove the scope rule with a made-up stack name
  // and never go anywhere near a real compose file.
  $args = $service !== '' ? ($verbs[$verb]['svc'] ?? '') : ($verbs[$verb]['args'] ?? '');
  // A verb with no form for a given scope simply has no key for it — `remove`
  // carries no `args` at all, `config` no `svc` — so the `?? ''` above is what
  // actually catches those. The `[]` test alongside it is
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

  $dir  = staxx_stack_dir($name);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

  // Catches a compose file dropped into this folder by hand and started
  // without ever being opened here first — the one window the editor's own
  // seed call cannot cover. A no-op once any history exists, so this can
  // only ever do real work on the very first run against such a stack.
  // Never blocks the run itself: a job is what was asked for, not a history
  // entry.
  $seedNote = '';
  if (!staxx_record_seed($name, $seedNote) && $seedNote !== '') {
    error_log('StaXX: history not seeded for '.$name.': '.$seedNote);
  }

  // A file still holding STAXX_PLACEHOLDER cannot be started — half-filled
  // either way, so this applies at both whole-stack and single-service scope.
  // Only for a verb that actually brings something up: `down`, `logs` and the
  // like are harmless against a file nobody has finished filling in yet.
  if ($startsSomething) {
    $waiting = staxx_placeholders($file);
    if ($waiting) {
      $shown = array_slice($waiting, 0, 6);
      $more  = count($waiting) - count($shown);
      $list  = implode(', ', $shown).($more > 0 ? ', and '.$more.' more' : '');
      // Spelled out for small counts, the same way the rest of the plugin's
      // messages read as sentences rather than a number stapled to a noun.
      $words = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];
      $n     = count($waiting);
      $count = $words[$n] ?? (string)$n;
      $error = ($n === 1
                 ? $count.' value still needs filling in: '.$list.'. Open the stack and fill it in.'
                 : $count.' values still need filling in before this stack can start: '
                   .$list.'. Open the stack and fill them in.');
      return '';
    }
  }

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

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
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
  @chmod($log, 0600);

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
 * Read the bytes a job's log has gained since $offset — not the whole file,
 * so a poll every second or two on a busy folder is one small read rather
 * than re-sending everything already shown.
 *
 * @return array{text:string, offset:int, done:bool, exit:?int}
 */
function staxx_job_log(string $job, int $offset = 0): array {
  // An unknown or malformed id is reported as finished-with-no-exit-code
  // rather than as an error: the browser treats a null exit as a silent
  // finish, which is what stops a stale id (log already pruned, or a typo)
  // spinning its row for ever waiting on a reply that can never arrive.
  if (!preg_match('/^[0-9a-f]{16}$/', $job)) {
    return ['text' => '', 'offset' => 0, 'done' => true, 'exit' => null];
  }

  $log = STAXX_JOB_DIR.'/'.$job.'.log';
  if (!is_file($log)) return ['text' => '', 'offset' => 0, 'done' => true, 'exit' => null];

  // A negative offset is clamped to 0 rather than trusted — file_get_contents()
  // seeks backward from the end of the file for a negative one, which would
  // hand back an arbitrary tail instead of refusing outright. An offset past
  // the end of the file is left alone: that is simply "nothing new since the
  // last poll" and must read as an empty chunk, not be reset to 0 — resetting
  // it would re-send everything already shown on every poll after a job ends.
  if ($offset < 0) $offset = 0;

  // Capped: a first poll against a long-running `pull` used to hand back the
  // whole log in one go. $newOffset only ever advances by what was actually
  // read, so a capped chunk just means the next poll picks up where this one
  // left off rather than losing anything.
  $chunk = @file_get_contents($log, false, null, $offset, STAXX_LOG_CHUNK_MAX);
  if ($chunk === false) $chunk = '';
  // Measured on the raw chunk, before the sentinel below is stripped out of
  // $text — otherwise the offset would undercount by the sentinel's own
  // length and the next poll would re-read (and re-detect) it for ever.
  $newOffset = $offset + strlen($chunk);

  $done = false;
  $exit = null;
  $text = $chunk;
  if (preg_match('/^'.preg_quote(STAXX_JOB_END, '/').' (\d+)\s*$/m', $chunk, $m)) {
    $done = true;
    $exit = (int)$m[1];
    $text = preg_replace('/^'.preg_quote(STAXX_JOB_END, '/').' \d+\s*$/m', '', $chunk);
  }

  // Deliberately not trim()'d: this is only the new bytes since the caller's
  // last poll, appended to what it already has, so leading whitespace here is
  // not noise to tidy away — it can be the start of a new line and trimming
  // it would glue two lines together on the page.
  return ['text' => $text, 'offset' => $newOffset, 'done' => $done, 'exit' => $exit];
}

/** Remove job logs older than an hour, so /tmp does not fill up over time. */
function staxx_prune_jobs(): void {
  if (!is_dir(STAXX_JOB_DIR)) return;
  foreach ((array)@glob(STAXX_JOB_DIR.'/*.log') as $log) {
    if (@filemtime($log) >= time() - 3600) continue;

    // A log with no sentinel yet is still being appended to by a live job —
    // an hour is a long time for an ordinary command, but not for a slow
    // image pull, and deleting the file out from under `setsid` would make
    // that job vanish rather than finish. Reading the last line is enough:
    // the sentinel is always the final thing written.
    $tail = (string)@file_get_contents($log, false, null, max(0, (int)@filesize($log) - 64));
    if (strpos($tail, STAXX_JOB_END) === false) continue;

    @unlink($log);
  }
}

/* --------------------------------------------------------------- logs ----
 *
 * The log pane's server side: a detached `compose logs --follow` per
 * container-or-whole-stack, the same detach-and-poll-by-offset shape as a
 * job, but with a different lifetime. A job ends on its own; a follower does
 * not, so something has to end it instead — the heartbeat rule
 * scripts/stats-collector.sh already proved: the page touches a timestamp
 * every time it asks for more output, and the follower is reaped once that
 * goes stale. Close the tab and it stops on its own within STAXX_LOG_STALE
 * seconds, without anybody having to remember to clean it up.
 *
 * Three files per follower id, all under STAXX_LOG_DIR:
 *   <id>.log  the accumulated output, read by offset like a job's log
 *   <id>.pid  the process group id of the detached `compose logs`, so it can
 *             be found again and killed
 *   <id>.hb   the epoch second of the last poll — the heartbeat itself
 */

/**
 * Start a `compose logs --follow` for one stack, either scoped to a single
 * service or, with $service === '', interleaved across the whole stack.
 *
 * `compose logs`, not `docker logs`: compose already prefixes each line with
 * the service that said it, which is exactly what the *All* tab wants, and
 * it means one code path for both cases — a single service is the same
 * command with --no-log-prefix and the service named.
 */
function staxx_log_start(string $stack, string $service, string &$error): string {
  $error = '';

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }

  // Checked before anything about the environment or the service, exactly as
  // staxx_start_job() orders it: a locked stack is refused for the right
  // reason even when compose or docker also happen to be unavailable.
  if (staxx_review_locked($stack)) {
    $error = 'This stack was imported and has not been reviewed yet. Open it, read '
           . STAXX_REVIEW_FILE . ', then choose "Take over and start" or "Clear the lock only"'
           . ' before viewing its logs.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

  $tail = 'logs --follow --tail 200 --timestamps';
  if ($service !== '') {
    // Membership against the compose file's own services, the same rule and
    // the same reason staxx_start_job() checks a service name against —
    // a regex on shape would accept a name this stack does not have.
    // escapeshellarg() on top of that, belt and braces, same as there.
    $services = staxx_compose_meta($file)['services'];
    if (!isset($services[$service])) {
      $error = 'No service called "'.$service.'" in this stack.';
      return '';
    }
    $tail .= ' --no-log-prefix '.escapeshellarg($service);
  }

  // A crashed browser cannot leave a follower running forever: every action
  // that touches a follower reaps stale ones first, this one included, so a
  // stack of them cannot build up under someone who never closes a tab.
  staxx_log_reap();

  if (!staxx_private_dir(STAXX_LOG_DIR)) {
    $error = 'Could not create '.STAXX_LOG_DIR;
    return '';
  }

  $id      = bin2hex(random_bytes(8));
  $log     = STAXX_LOG_DIR.'/'.$id.'.log';
  $pidfile = STAXX_LOG_DIR.'/'.$id.'.pid';
  $hbfile  = STAXX_LOG_DIR.'/'.$id.'.hb';

  // Unlike a job's log, nothing here writes $log before the detached shell
  // starts — its own `>>` redirect is what creates the file. Created here
  // instead, empty, so the mode can be locked down before compose's output
  // (which can include a resolved environment variable) ever lands in it;
  // the shell's redirect then just appends to a file that already exists.
  @file_put_contents($log, '');
  @chmod($log, 0600);

  // The heartbeat is written before the follower even starts, so a poll that
  // lands in the gap between this call returning and the process actually
  // existing still sees a fresh timestamp rather than reading as stale.
  @file_put_contents($hbfile, (string)time());

  // `echo $$` runs inside the detached shell BEFORE `exec` replaces it with
  // compose, so it captures compose's own pid — and because setsid made this
  // shell the leader of a new session, that pid is also the process group id
  // the whole thing can later be killed by.
  $inner = 'cd '.escapeshellarg($dir).' && echo $$ > '.escapeshellarg($pidfile)
         . ' && exec '.$cmd.' '.staxx_compose_file_args($files).' '.$tail;

  // Deliberately NOT run through staxx_sh(): that helper wraps everything in
  // `timeout` on purpose, because a page that waits forever on Docker is
  // worse than one that fails. A follower is the opposite case — it is
  // MEANT to keep running for as long as somebody is watching — so its
  // lifetime is governed by the heartbeat above and staxx_log_reap() below,
  // never by a fixed clock. Do not "fix" this by adding a timeout.
  @exec(
    'setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &'
  );

  return $id;
}

/**
 * Read the bytes a follower's log has gained since $offset — the same
 * offset contract as staxx_job_log(): only the new bytes, and the new offset
 * measured on the raw chunk. There is no sentinel and no exit code here — a
 * follower ends when the containers stop or when it is reaped, not on its
 * own schedule.
 *
 * Asking for more output IS the keep-alive signal, so this is the only place
 * the heartbeat file gets touched — there is no separate keep-alive action.
 *
 * @return array{text:string, offset:int, alive:bool}
 */
function staxx_log_read(string $id, int $offset): array {
  // Reaping runs on every read, not just on start, so a follower whose tab
  // was closed stops within one stale interval regardless of which other tab
  // happens to poll next.
  staxx_log_reap();

  if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    return ['text' => '', 'offset' => 0, 'alive' => false];
  }

  $log = STAXX_LOG_DIR.'/'.$id.'.log';
  if (!is_file($log)) return ['text' => '', 'offset' => 0, 'alive' => false];

  // Same clamp as staxx_job_log(): a negative offset would seek backward from
  // the end of the file instead of being refused outright.
  if ($offset < 0) $offset = 0;

  // Capped for the same reason as staxx_job_log() — a first poll against a
  // follower nobody has read from in a while must not hand back everything
  // it has buffered in one reply.
  $chunk = @file_get_contents($log, false, null, $offset, STAXX_LOG_CHUNK_MAX);
  if ($chunk === false) $chunk = '';
  $newOffset = $offset + strlen($chunk);

  // The touch that keeps this follower alive. Written after the read, not
  // before, so a request that fails partway through never claims a poll that
  // did not actually happen.
  @file_put_contents(STAXX_LOG_DIR.'/'.$id.'.hb', (string)time());

  // The pid file can briefly not exist yet — staxx_log_start() writes it from
  // inside the detached shell, after this call has already returned an id —
  // so its absence reads as "still starting" rather than "gone", and only a
  // pid file naming a process that is no longer there means the follower
  // itself has ended.
  $pidfile = STAXX_LOG_DIR.'/'.$id.'.pid';
  if (!is_file($pidfile)) {
    $alive = true;
  } else {
    $pid = (int)trim((string)@file_get_contents($pidfile));
    $alive = $pid > 0 && is_dir('/proc/'.$pid);
  }

  return ['text' => $chunk, 'offset' => $newOffset, 'alive' => $alive];
}

/**
 * Stop one follower outright — the page closed the tab or switched to a
 * different container. Kills the process (if it is still going) and removes
 * all three of its files so staxx_log_reap() has nothing left to find.
 */
function staxx_log_stop(string $id): void {
  if (!preg_match('/^[0-9a-f]{16}$/', $id)) return;
  staxx_log_kill($id);
  foreach (['log', 'pid', 'hb'] as $ext) @unlink(STAXX_LOG_DIR.'/'.$id.'.'.$ext);
}

/**
 * Kill the follower named by $id, if its pid file names a process that is
 * still plausibly ours.
 *
 * Pid reuse on a long-running Linux box is a real, if narrow, risk — the
 * process this pid once named could in principle have exited and the number
 * been handed to something unrelated before this runs. So this is
 * deliberately defensive rather than trusting the pid file outright: it only
 * signals a pid whose own command line still contains both "compose" and
 * "logs", which is what every follower this function ever started looks
 * like. Even in the unlikely case a recycled pid still passes that check, it
 * can only belong to some OTHER `compose … logs` invocation — stopping a log
 * stream is not a destructive action, so the worst case of a false match is
 * harmless.
 */
function staxx_log_kill(string $id): void {
  $pid = (int)trim((string)@file_get_contents(STAXX_LOG_DIR.'/'.$id.'.pid'));
  if ($pid <= 0 || !is_dir('/proc/'.$pid)) return;

  $cmdline = str_replace("\0", ' ', (string)@file_get_contents('/proc/'.$pid.'/cmdline'));
  if (strpos($cmdline, 'compose') === false || strpos($cmdline, 'logs') === false) return;

  // setsid made this pid the leader of its own process group when the
  // follower started, so signalling the NEGATIVE pid reaches the whole
  // group — compose and anything it spawned — not just this one process.
  @exec('kill -TERM -'.$pid.' 2>/dev/null');
}

/**
 * Kill and remove every follower whose heartbeat has gone stale, and clear
 * out any log file left over from one already gone. Called at the top of
 * every log action, so a crashed browser can never leave a follower running
 * — nobody has to remember to close it.
 */
function staxx_log_reap(): void {
  if (!is_dir(STAXX_LOG_DIR)) return;

  foreach ((array)@glob(STAXX_LOG_DIR.'/*.hb') as $hb) {
    $id   = basename($hb, '.hb');
    $seen = (int)trim((string)@file_get_contents($hb));
    $log  = STAXX_LOG_DIR.'/'.$id.'.log';
    // A heartbeat this fresh means someone is still watching, so age alone
    // would never catch a follower stuck writing gigabytes to a live tab —
    // the size ceiling is the only thing that reaps that one.
    $tooBig = is_file($log) && (int)@filesize($log) > STAXX_LOG_SIZE_MAX;
    if ($tooBig || time() - $seen > STAXX_LOG_STALE) {
      staxx_log_kill($id);
      foreach (['log', 'pid', 'hb'] as $ext) @unlink(STAXX_LOG_DIR.'/'.$id.'.'.$ext);
    }
  }

  // Belt and braces, the same way staxx_prune_jobs() clears job logs: a log
  // file whose heartbeat is already gone (staxx_log_stop() already ran, or a
  // previous reap already caught it) but is simply old is cleared out too.
  foreach ((array)@glob(STAXX_LOG_DIR.'/*.log') as $log) {
    if (@filemtime($log) < time() - 3600) @unlink($log);
  }
}

/**
 * The whole current log as one string, for the "download all" control —
 * answered inline in the JSON reply rather than as a file download, since
 * this endpoint only ever answers JSON. Not detached: this runs once and
 * returns, so the ordinary time-limited runner is the right tool here,
 * unlike the live follower above.
 */
function staxx_log_download(string $stack, string $service, string &$error): string {
  $error = '';

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }

  if (staxx_review_locked($stack)) {
    $error = 'This stack was imported and has not been reviewed yet. Open it, read '
           . STAXX_REVIEW_FILE . ', then choose "Take over and start" or "Clear the lock only"'
           . ' before viewing its logs.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }
  $files = staxx_compose_files($file);

  $args = 'logs --tail 2000 --timestamps';
  if ($service !== '') {
    $services = staxx_compose_meta($file)['services'];
    if (!isset($services[$service])) {
      $error = 'No service called "'.$service.'" in this stack.';
      return '';
    }
    $args .= ' --no-log-prefix '.escapeshellarg($service);
  }

  $text = staxx_sh($cmd.' '.staxx_compose_file_args($files).' '.$args, 30);

  if (strlen($text) > STAXX_LOG_DOWNLOAD_MAX) {
    $text = substr($text, -STAXX_LOG_DOWNLOAD_MAX)
          . "\n\n[... truncated to the last ".number_format(STAXX_LOG_DOWNLOAD_MAX)." bytes ...]";
  }

  return $text;
}

/* ========================================================== exec (D4) =====
 *
 * A real shell inside a running container. See PLAN_44 section D4. This is
 * the one place in the plugin where user input reaches a shell with no verb
 * allowlist standing in front of it — docker exec runs whatever the person
 * inside the session types. Every guard below exists because of that.
 *
 * The mechanism, proved by hand on the real server before any of this was
 * written (socat is not installed there; script, mkfifo and setsid are):
 *
 *   echo $$ > pid; exec 9<> fifo; script -qfc "<docker exec …>" /dev/null \
 *     <&9 >>out 2>&1
 *
 * `script -qfc` is what allocates a pseudo-terminal — without one there is
 * no prompt, no tab completion and no Ctrl-C, because `docker exec -it`
 * refuses outright when its stdin is a plain pipe rather than a terminal.
 * `exec 9<>` opens the fifo READ-WRITE in the session's own shell, so that
 * shell is always a second writer holding the pipe open on top of whatever
 * PHP does. That is what lets a web request fopen() the fifo, write one
 * keystroke, and close it again — once per request — without the far end
 * ever seeing end-of-input. Proved the failure case too: a writer that only
 * opens and closes per keystroke, with nobody else holding the pipe open,
 * kills the shell immediately. Do not "simplify" this by dropping the
 * `exec 9<>` line — it is not decorative.
 *
 * Four files per session, all under STAXX_EXEC_DIR/<id>/, the same shape a
 * log follower already uses:
 *   fifo  the named pipe PHP writes keystrokes into
 *   out   the accumulated output, read by offset like a job's log
 *   pid   the pid of the detached session leader (the outer `sh -c`), so it
 *         can be found again and killed
 *   hb    the epoch second of the last poll — the same heartbeat rule
 *         staxx_log_reap() already proved; STAXX_LOG_STALE is reused rather
 *         than inventing a second number for the same idea
 */

/**
 * The one real container behind a stack's service, resolved entirely from
 * what compose and docker themselves report — never from a name the browser
 * sent. staxx_state_for() recovers the project's current identity the same
 * way the row's own status column does (by file, then by tail, then by
 * project name, so a stack that has been moved is still found); docker's
 * own project/service labels then pick out the one container that actually
 * belongs to it. This is the difference between an allowlist and a hope: the
 * client sends a stack path and a service name, and nothing else it sends is
 * ever capable of naming a container.
 */
function staxx_exec_resolve_container(string $file, string $leaf, string $service, string &$error): string {
  $state   = staxx_state_for($file, $leaf);
  $project = $state['name'] ?? '';
  if ($project === '') {
    $error = 'This stack does not appear to be running.';
    return '';
  }

  // `.Label "key"` looks up one label by name — the same trick
  // staxx_containers_by_project() uses, because the obvious-looking
  // `index .Labels "key"` fails the whole command instead of the one lookup.
  $fmt = '{{.Names}}\t{{.Label "com.docker.compose.project"}}\t'
       . '{{.Label "com.docker.compose.service"}}\t{{.State}}';
  $out = staxx_sh(escapeshellarg(staxx_docker_bin()).' ps -a --format '.escapeshellarg($fmt), 10);

  // A scaled service, or one restarted while an old exited copy still
  // lingers, can list more than one match for the same project and service —
  // an exited leftover must never hide a sibling that is actually running, so
  // every line is checked before giving up.
  $foundStopped = false;
  foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    [$cname, $proj, $svc, $state2] = array_pad(explode("\t", $line, 4), 4, '');
    if ($proj !== $project || $svc !== $service) continue;
    if ($state2 !== 'running') { $foundStopped = true; continue; }
    return $cname;
  }

  $error = $foundStopped
    ? 'That container is not running, so there is nothing to open a shell into.'
    : 'No running container for service "'.$service.'" in this stack.';
  return '';
}

/**
 * Open a shell session in the running container behind one service of one
 * stack, and return its session id, or '' with $error set.
 *
 * Guarded, in order: a valid stack path; the SHELL_ENABLED setting, checked
 * here and not only hidden away in the browser, so a hand-built request is
 * refused exactly as a click would be; the review lock, same reason and same
 * order staxx_start_job() and staxx_log_start() use it; compose and docker
 * actually being available; the service being a real member of this stack's
 * own compose file, the same membership rule every other verb checks a
 * service name against; and finally a container that is both resolved
 * server-side (see staxx_exec_resolve_container()) and actually running.
 */
function staxx_exec_start(string $stack, string $service, string &$error): string {
  $error = '';

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }

  if (!staxx_cfg_bool('SHELL_ENABLED')) {
    $error = 'Shell access to containers is turned off in Settings.';
    return '';
  }

  if (staxx_review_locked($stack)) {
    $error = 'This stack was imported and has not been reviewed yet. Open it, read '
           . STAXX_REVIEW_FILE . ', then choose "Take over and start" or "Clear the lock only"'
           . ' before opening a shell.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }

  $services = staxx_compose_meta($file)['services'];
  if (!isset($services[$service])) {
    $error = 'No service called "'.$service.'" in this stack.';
    return '';
  }

  $container = staxx_exec_resolve_container($file, staxx_path_leaf($stack), $service, $error);
  if ($container === '') return ''; // $error already set

  // A crashed browser cannot leave a session running forever: every action
  // that touches one reaps stale sessions first, this one included.
  staxx_exec_reap();

  if (!is_dir(STAXX_EXEC_DIR) && !@mkdir(STAXX_EXEC_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_EXEC_DIR;
    return '';
  }

  $id      = bin2hex(random_bytes(8));
  $sessDir = STAXX_EXEC_DIR.'/'.$id;
  if (!@mkdir($sessDir, 0700, true)) {
    $error = 'Could not create a session folder.';
    return '';
  }

  $fifo = $sessDir.'/fifo';
  $out  = $sessDir.'/out';
  $pid  = $sessDir.'/pid';
  $hb   = $sessDir.'/hb';

  // mkfifo, not touch — a plain file here would let the write below succeed
  // by simply appending to it, with nothing on the other end ever reading a
  // byte, and the session would look open while going nowhere.
  @exec('mkfifo -m 600 '.escapeshellarg($fifo));
  if (!file_exists($fifo)) {
    @exec('rm -rf '.escapeshellarg($sessDir));
    $error = 'Could not create the input pipe.';
    return '';
  }
  @touch($out);

  // Written before the session even starts, exactly as staxx_log_start()
  // writes its heartbeat first — a poll landing in the gap between this call
  // returning and the process actually existing still sees a fresh
  // timestamp rather than reading as stale.
  @file_put_contents($hb, (string)time());

  // Root inside the container, falling back to sh for an image with no bash.
  //
  // Written `exec bash || exec sh` this never fell back and every bash-less
  // image — which is most Alpine ones — got a session that died the instant
  // it started, showing "bash: not found" and then refusing every keystroke
  // as a session that had ended. A failed `exec` TERMINATES a
  // non-interactive shell, so the `||` branch is unreachable; measured on
  // the server, `sh -c 'exec nosuchcmd || exec echo ran'` prints the error
  // and exits 127 without ever running the echo. Asking first, with
  // `command -v`, is what makes the choice instead of hoping a failure is
  // recoverable.
  //
  // If neither shell exists, docker exec itself fails and its own error text
  // lands in $out — staxx_exec_read() then reports alive:false with that
  // text already in it, which is the honest answer for an image with no
  // shell at all, not an empty session that looks broken.
  $execCmd = escapeshellarg(staxx_docker_bin())
           . ' exec -u 0 -it '.escapeshellarg($container)
           . ' sh -c '.escapeshellarg('command -v bash >/dev/null 2>&1 && exec bash; exec sh');

  // `echo $$` runs in the outer shell before anything else, capturing the
  // session leader's own pid — and because setsid below makes this shell the
  // leader of a new session, that pid is also the process group id the
  // whole session can later be killed by, the same trick staxx_log_start()
  // uses for its follower.
  $inner = 'echo $$ > '.escapeshellarg($pid).'; '
         . 'exec 9<> '.escapeshellarg($fifo).'; '
         . 'script -qfc '.escapeshellarg($execCmd).' /dev/null <&9 >> '.escapeshellarg($out).' 2>&1';

  // NOT wrapped in staxx_sh()'s timeout, deliberately — a session is meant
  // to live for as long as the editor tab stays open, not for a fixed number
  // of seconds. Its lifetime is the heartbeat above and staxx_exec_reap()
  // below, exactly as staxx_log_start() already argues for its own
  // follower. Do not "fix" this by adding a timeout.
  @exec('setsid sh -c '.escapeshellarg($inner).' </dev/null >/dev/null 2>&1 &');

  return $id;
}

/**
 * Is this session's leader still running? A missing pid file reads as
 * alive — staxx_exec_start() writes it from inside the detached shell, a
 * moment after this call already returned an id, the same timing gap
 * staxx_log_read() already tolerates for a follower's pid file.
 */
function staxx_exec_alive(string $id): bool {
  $pidfile = STAXX_EXEC_DIR.'/'.$id.'/pid';
  if (!is_file($pidfile)) return true;
  $pid = (int)trim((string)@file_get_contents($pidfile));
  return $pid > 0 && is_dir('/proc/'.$pid);
}

/**
 * Write one keystroke or short paste into a session's input pipe.
 *
 * The bytes go in through fopen(), fwrite() and fclose() only — never
 * assembled into a shell command line. That is the single most important
 * line in this whole file: there is no quoting to get wrong if no shell ever
 * sees these bytes as anything other than raw input.
 *
 * The alive check narrows, but cannot fully close, the gap between "the
 * session leader just exited" and this write actually happening — a fifo's
 * write side blocks until a reader is present, so writing into one with
 * nobody left to read it would hang this request rather than fail it. The
 * residual race (the leader dying in the instant between the check and the
 * fopen()) is accepted rather than engineered around, the same way
 * staxx_log_kill() accepts a narrow pid-reuse window: the worst case here is
 * one request blocking briefly, not a wrong container being touched.
 */
function staxx_exec_write(string $id, string $bytes, string &$error): bool {
  $error = '';

  if (!preg_match('/^[0-9a-f]{16}$/', $id)) { $error = 'Invalid session id.'; return false; }

  staxx_exec_reap();

  if (strlen($bytes) > STAXX_EXEC_WRITE_MAX) {
    $error = 'That is too much to send at once — paste it in smaller pieces.';
    return false;
  }

  $fifo = STAXX_EXEC_DIR.'/'.$id.'/fifo';
  if (!file_exists($fifo) || !staxx_exec_alive($id)) {
    $error = 'That session has ended.';
    return false;
  }

  // 'r+', not 'a'. Opening a pipe for writing alone BLOCKS until something
  // opens the other end, and if the session has just died nothing ever will —
  // so a keystroke arriving in that gap would hang this request for ever, with
  // no timeout to save it. Opening read-write never blocks, which is the same
  // trick the session's own launcher uses (`exec 9<>`) to keep the pipe from
  // seeing end-of-input. Measured on the server: with no reader, 'a' was still
  // blocked after two seconds and 'r+' returned in a tenth of one.
  //
  // The bytes never touch a shell command line — this is a file write, not a
  // command — which is what makes a shell session safe to expose at all: there
  // is no quoting to get wrong, whatever somebody types.
  $fh = @fopen($fifo, 'r+');
  if ($fh === false) { $error = 'Could not write to that session.'; return false; }
  fwrite($fh, $bytes);
  fclose($fh);
  return true;
}

/**
 * Read the bytes a session's output has gained since $offset — the same
 * offset contract as staxx_job_log() and staxx_log_read(): only the new
 * bytes since the caller's last poll, measured on the raw chunk, leading
 * whitespace left intact rather than trimmed away.
 *
 * Asking for output IS the keep-alive signal, exactly as it is for a log
 * follower — this is the only place a session's heartbeat gets touched.
 *
 * @return array{text:string, offset:int, alive:bool}
 */
function staxx_exec_read(string $id, int $offset): array {
  if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    return ['text' => '', 'offset' => 0, 'alive' => false];
  }

  staxx_exec_reap();

  $out = STAXX_EXEC_DIR.'/'.$id.'/out';
  if (!is_file($out)) return ['text' => '', 'offset' => 0, 'alive' => false];

  // Same clamp staxx_job_log() and staxx_log_read() use: a negative offset
  // would otherwise seek backward from the end of the file instead of being
  // refused outright.
  if ($offset < 0) $offset = 0;

  // Capped for the same reason as staxx_job_log() and staxx_log_read().
  $chunk = @file_get_contents($out, false, null, $offset, STAXX_LOG_CHUNK_MAX);
  if ($chunk === false) $chunk = '';
  $newOffset = $offset + strlen($chunk);

  // Written after the read, not before, so a request that fails partway
  // through never claims a poll that did not actually happen.
  @file_put_contents(STAXX_EXEC_DIR.'/'.$id.'/hb', (string)time());

  return ['text' => $chunk, 'offset' => $newOffset, 'alive' => staxx_exec_alive($id)];
}

/**
 * Kill the session named by $id, if its pid file names a process that is
 * still plausibly ours — the same pid-reuse defence staxx_log_kill() uses,
 * checked here against "script", the one program every session this
 * function ever started is running inside its detached shell.
 */
function staxx_exec_kill(string $id): void {
  $pid = (int)trim((string)@file_get_contents(STAXX_EXEC_DIR.'/'.$id.'/pid'));
  if ($pid <= 0 || !is_dir('/proc/'.$pid)) return;

  $cmdline = str_replace("\0", ' ', (string)@file_get_contents('/proc/'.$pid.'/cmdline'));
  if (strpos($cmdline, 'script') === false) return;

  // setsid made this pid the leader of its own process group when the
  // session started, so signalling the NEGATIVE pid reaches the whole
  // group — the shell, script, and docker exec itself — not just this one
  // process.
  @exec('kill -TERM -'.$pid.' 2>/dev/null');
}

/**
 * Stop one session outright — the editor closed, or the container tab
 * switched away and its shell is being ended for good, not merely left to
 * time out. Kills the process group if it is still going, then removes the
 * whole session directory so staxx_exec_reap() has nothing left to find.
 */
function staxx_exec_stop(string $id): void {
  if (!preg_match('/^[0-9a-f]{16}$/', $id)) return;
  staxx_exec_kill($id);
  @exec('rm -rf '.escapeshellarg(STAXX_EXEC_DIR.'/'.$id));
}

/**
 * Kill and remove every session whose heartbeat has gone stale, reusing
 * STAXX_LOG_STALE rather than a second number for the same idea. Called at
 * the top of every exec action, so a crashed browser can never leave a shell
 * running in somebody's container — nobody has to remember to close it.
 */
function staxx_exec_reap(): void {
  if (!is_dir(STAXX_EXEC_DIR)) return;

  foreach ((array)@glob(STAXX_EXEC_DIR.'/*/hb') as $hb) {
    $seen = (int)trim((string)@file_get_contents($hb));
    if (time() - $seen > STAXX_LOG_STALE) {
      $sessDir = dirname($hb);
      staxx_exec_kill(basename($sessDir));
      @exec('rm -rf '.escapeshellarg($sessDir));
    }
  }
}

/* ============================================================= container files ===
 *
 * A file manager for the inside of a running container, gated by the SAME
 * SHELL_ENABLED switch as the shell above — not a switch of its own, because
 * it is the same capability wearing a different hat: both let the browser
 * reach into whatever is inside a container. Being honest about that is why
 * one switch covers both rather than two that would always be flipped
 * together.
 *
 * Listings and the small operations (rename, delete, mkdir) go through
 * `docker exec`, one short-lived command at a time. File *contents* go
 * through `docker cp` instead — it is binary-safe and needs no shell inside
 * the container at all, so an image that ships none still gets a working
 * editor and upload/download. Every command below is built with $path
 * arguments AFTER a literal `--`, so a name that happens to start with "-"
 * can never be read as a flag, and every one of those arguments is
 * escapeshellarg()'d regardless — belt and braces, the same as everywhere
 * else a path reaches a shell in this file.
 *
 * Command strings are built by small helpers rather than inline, purely so a
 * test can assert on the string itself without a real container to run it
 * against — see tests/server/console.php.
 */

/**
 * A path inside a container is data about a filesystem this host cannot see,
 * so there is no realpath() to resolve it against the way a stack's own
 * files are checked. It is refused instead of normalised: must be absolute,
 * must not contain a ".." segment anywhere, and kept to a sane length.
 */
function staxx_cfile_valid_path(string $path): bool {
  if ($path === '' || $path[0] !== '/' || strlen($path) > 4096) return false;
  foreach (explode('/', $path) as $seg) {
    if ($seg === '..') return false;
  }
  return true;
}

function staxx_cfile_ls_cmd(string $container, string $dir): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' ls -lAn -- '.escapeshellarg($dir);
}

function staxx_cfile_mv_cmd(string $container, string $from, string $to): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' mv -- '.escapeshellarg($from).' '.escapeshellarg($to);
}

function staxx_cfile_rm_cmd(string $container, string $path, bool $recurse): string {
  // Without -r, rm's own refusal on a directory ("Is a directory") is what
  // enforces "a directory needs the recurse flag" — there is no separate
  // stat call first to tell a file from a folder, rm already knows how.
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' rm '.($recurse ? '-rf' : '-f').' -- '.escapeshellarg($path);
}

/**
 * Ownership, always recursive. On a single file -R means nothing; on a folder
 * it is what "fix the ownership" asks for, so there is no flag to get wrong.
 * The owner is validated as digits by staxx_cfile_chown() before it reaches
 * here, and escaped on top of that.
 */
function staxx_cfile_chown_cmd(string $container, string $owner, string $path): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' chown -R '.escapeshellarg($owner).' -- '.escapeshellarg($path);
}

/**
 * Permissions, recursive for the same reason ownership is. The mode is
 * validated as three or four octal digits by staxx_cfile_chmod() before it
 * reaches here, and escaped on top of that.
 */
function staxx_cfile_chmod_cmd(string $container, string $mode, string $path): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' chmod -R '.escapeshellarg($mode).' -- '.escapeshellarg($path);
}

function staxx_cfile_mkdir_cmd(string $container, string $path): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container)
       . ' mkdir -p -- '.escapeshellarg($path);
}

function staxx_cfile_cp_out_cmd(string $container, string $path, string $hostTemp): string {
  return escapeshellarg(staxx_docker_bin()).' cp -- '
       . escapeshellarg($container.':'.$path).' '.escapeshellarg($hostTemp);
}

function staxx_cfile_cp_in_cmd(string $container, string $hostTemp, string $path): string {
  return escapeshellarg(staxx_docker_bin()).' cp -- '
       . escapeshellarg($hostTemp).' '.escapeshellarg($container.':'.$path);
}

/* ============================================================= PLAN_108 health trial ===
 *
 * Working out whether a health check CAN be offered, never applying one —
 * that stays entirely a compose-file edit through the existing save route.
 * Both functions below take an already-resolved container name (the same
 * kind staxx_cfile_container() hands back, never a name typed by the
 * browser), and both share one short timeout so a hung candidate can never
 * hold a PHP worker open.
 */

// Long enough for a client tool to open a real connection and answer, short
// enough that a person waiting on the chooser is never left staring at it.
define('STAXX_HEALTH_TRIAL_TIMEOUT', 8);

/** Docker's own name rule, checked before a name reaches a shell on top of
 *  (not instead of) escapeshellarg() — the same belt-and-braces every other
 *  exec helper in this section applies. */
function staxx_health_valid_container(string $container): bool {
  return $container !== '' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $container) === 1;
}

/**
 * Which of curl and wget exist inside a running container — the two tools
 * the web-ping source (PLAN_108 step 4) can reach for. One `docker exec`,
 * `command -v` rather than `which`, because `which` is itself missing from
 * plenty of minimal images.
 *
 * @return array{curl:bool, wget:bool}
 */
function staxx_health_tools(string $container, string &$why): array {
  $why = '';
  if (!staxx_health_valid_container($container)) { $why = 'That is not a valid container name.'; return []; }

  // The trailing `exit 0` is load-bearing, and its absence was measured on a
  // real container rather than reasoned about: a shell exits with the status
  // of its LAST command, so a container without wget made the final
  // `command -v` fail, short-circuit its `&&`, and hand back 127 — reported
  // as "could not ask this container anything" for a probe that in fact ran
  // perfectly and correctly found nothing. Since most images carry neither
  // tool, that silently retired the web-ping source almost everywhere. The
  // exit code here must say whether the QUESTION could be asked; what the
  // answer was is read from the output.
  $probe = 'command -v curl >/dev/null 2>&1 && echo CURL_YES; '
         . 'command -v wget >/dev/null 2>&1 && echo WGET_YES; '
         . 'exit 0';
  $code = null;
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container).' sh -c '.escapeshellarg($probe),
    5, $code
  );
  if ($code !== 0) { $why = 'Could not ask that container what tools it has inside it.'; return []; }

  return [
    'curl' => strpos($out, 'CURL_YES') !== false,
    'wget' => strpos($out, 'WGET_YES') !== false,
  ];
}

/**
 * What a `docker exec` exit code says about whether a candidate check CAN
 * run — '' for "it ran fine", a plain sentence otherwise. Split out as its
 * own pure function purely so the exit-code judgement can be tested with a
 * made-up number, the same reasoning staxx_parse_image_healthcheck() is
 * split out for, rather than needing a real container to produce one.
 */
function staxx_health_trial_verdict(int $code): string {
  if ($code === 0) return '';

  if ($code === 127) {
    return 'This image has nothing inside it to check with, so this check cannot be offered here.';
  }

  // 124 is `timeout`'s own "ran out of time"; 137 is what is left once the
  // grace period (`timeout -k 2`) has to finish the job with SIGKILL.
  if ($code === 124 || $code === 137) {
    return 'That command did not answer in time, so this check cannot be offered here.';
  }

  return 'That command failed just now, so this check cannot be offered here. '
       . 'The app may simply be busy at this moment, but the safe answer is not to offer a check that already failed once.';
}

/**
 * Undo compose's own `$$` -> `$` substitution — the one compose would have
 * done on the way from the YAML into the container. The trial runs the
 * candidate directly through `docker exec`, with compose nowhere in the
 * chain, so a recipe's `$$MYSQL_ROOT_PASSWORD` — written that way because
 * that IS the correct compose syntax for a literal dollar — would otherwise
 * reach the shell untouched, where the shell itself expands `$$` to its own
 * process id rather than leaving a variable name for the database's own
 * client to read. The command then fails for a reason that has nothing to
 * do with whether the check itself is right, and the trial would report a
 * perfectly good recipe as "failed just now" with no way to tell the two
 * apart. Only the trial does this substitution — nowhere else in this file
 * stands in for compose, so nowhere else should undo what compose does.
 *
 * str_replace() already pairs left to right, non-overlapping, which is the
 * same rule compose's own substitution follows: "$$$$" (two pairs) becomes
 * "$$", and a lone "$" with no partner is left exactly as it was.
 */
function staxx_health_collapse_dollars(string $s): string {
  return str_replace('$$', '$', $s);
}

/**
 * Run one CANDIDATE health-check command inside a running container, once,
 * and judge only whether it CAN run there — this never decides whether the
 * offer is a good idea, only whether the command has anything to execute.
 *
 * A refusal here always means "offer nothing", never "offer anyway" — see
 * PLAN_108's own framing: a check that cannot run reports unhealthy for
 * ever, which is worse than the blank box it would replace.
 *
 * One honest limit that cannot be designed around: a candidate can also
 * fail here because the app genuinely is unhealthy at this exact moment,
 * and this trial has no way to tell that apart from a command that cannot
 * run at all. Both read as "offer nothing" on purpose — it is the safe
 * direction, not a false negative worth chasing.
 */
function staxx_health_trial(string $container, $test, string &$why): bool {
  $why = '';

  $mode = is_array($test) ? (string)($test[0] ?? '') : '';
  if (!is_array($test) || ($mode !== 'CMD' && $mode !== 'CMD-SHELL')) {
    $why = 'That is not a health-check command StaXX understands.';
    return false;
  }

  $args = array_slice($test, 1);
  if ($args === []) {
    $why = 'That health-check command has no command in it, so there is nothing to try.';
    return false;
  }
  // Standing in for compose's own variable substitution — see
  // staxx_health_collapse_dollars()'s own comment for why this has to
  // happen here and nowhere else.
  $args = array_map('staxx_health_collapse_dollars', $args);

  if (!staxx_health_valid_container($container)) { $why = 'That is not a valid container name.'; return false; }

  $running = trim(staxx_sh(
    escapeshellarg(staxx_docker_bin()).' inspect -f '.escapeshellarg('{{.State.Running}}').' '.escapeshellarg($container),
    5
  ));
  if ($running !== 'true') {
    $why = 'That container is not running, so there is nothing to try a health check against.';
    return false;
  }

  $inner = $mode === 'CMD-SHELL'
    ? 'sh -c '.escapeshellarg((string)$args[0])
    : implode(' ', array_map('escapeshellarg', $args));

  $code = null;
  staxx_sh(
    escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container).' '.$inner,
    STAXX_HEALTH_TRIAL_TIMEOUT, $code
  );

  $why = staxx_health_trial_verdict((int)$code);
  return $why === '';
}

/**
 * Resolve the running container behind one service of one stack, gated by
 * the same order staxx_exec_start() uses for the shell: valid stack path,
 * the SHELL_ENABLED switch, the review lock, compose and Docker present, the
 * service actually named in the compose file, then a container that both
 * resolves and is running. Every staxx_cfile_*() function below calls this
 * first, so all of them share one place these refusals are enforced.
 */
function staxx_cfile_container(string $stack, string $service, string &$error): string {
  $error = '';
  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }

  if (!staxx_cfg_bool('SHELL_ENABLED')) {
    $error = 'Container file access is turned off in Settings, under the same switch as the shell.';
    return '';
  }

  if (staxx_review_locked($stack)) {
    $error = 'This stack was imported and has not been reviewed yet. Open it, read '
           . STAXX_REVIEW_FILE . ', then choose "Take over and start" or "Clear the lock only"'
           . ' before browsing files inside a container.';
    return '';
  }

  $cmd = staxx_compose_cmd();
  if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return ''; }
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }

  $services = staxx_compose_meta($file)['services'];
  if (!isset($services[$service])) {
    $error = 'No service called "'.$service.'" in this stack.';
    return '';
  }

  return staxx_exec_resolve_container($file, staxx_path_leaf($stack), $service, $error);
}

/**
 * List one directory inside a running container.
 *
 * @return array{dir:string, entries:array<int, array{name:string, size:int,
 *               perms:string, uid:string, gid:string, dir:bool,
 *               link:bool}>, more:bool}|null
 */
function staxx_cfile_list(string $stack, string $service, string $dir, string &$error): ?array {
  $error = '';
  if (!staxx_cfile_valid_path($dir)) { $error = 'That is not a valid absolute path.'; return null; }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return null;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_ls_cmd($container, $dir), 10, $code);
  if ($code !== 0) {
    // An image with no `ls` fails here with docker's own "executable file
    // not found" text — that is a true and plain-enough answer on its own,
    // so it is passed straight through rather than replaced.
    $error = trim($out) !== '' ? trim($out) : 'Could not list that folder inside the container.';
    return null;
  }

  $lines = explode("\n", trim($out));
  if (isset($lines[0]) && stripos($lines[0], 'total ') === 0) array_shift($lines); // ls -l's own summary line

  $entries = [];
  $more    = false;
  foreach ($lines as $line) {
    if ($line === '') continue;
    if (count($entries) >= STAXX_CFILE_LIST_MAX) { $more = true; break; }

    // ls -lAn columns, numeric owner/group so no name lookup is needed inside
    // the container: perms, link-count, uid, gid, size, month, day,
    // time-or-year, name. The name is everything after the eighth run of
    // whitespace, not a ninth split field, because it can itself hold spaces.
    if (!preg_match('/^(\S+)\s+\S+\s+(\S+)\s+(\S+)\s+(\S+)\s+\S+\s+\S+\s+\S+\s+(.*)$/', $line, $m)) continue;
    [, $perms, $uid, $gid, $size] = $m;
    $name = $m[5];
    if ($name === '.' || $name === '..') continue;

    $isLink = $perms[0] === 'l';
    if ($isLink) {
      // A symlink's ls -l entry reads "name -> target"; the arrow and target
      // are not part of the name.
      $arrow = strpos($name, ' -> ');
      if ($arrow !== false) $name = substr($name, 0, $arrow);
    }

    $entries[] = [
      'name'  => $name,
      'size'  => (int)$size,
      'perms' => $perms,
      'uid'   => $uid,
      'gid'   => $gid,
      'dir'   => $perms[0] === 'd',
      'link'  => $isLink,
    ];
  }

  return ['dir' => $dir, 'entries' => $entries, 'more' => $more];
}

/**
 * Read one file out of a running container via `docker cp` into a host temp
 * file, then hand it through exactly the same text/binary/size-cap rules
 * staxx_read_file() uses for a stack's own companion files — same contract,
 * a container just sits in front of it now.
 *
 * @return array{text?:string, b64?:string, binary:bool}|null
 */
function staxx_cfile_read(string $stack, string $service, string $path, string &$error): ?array {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return null; }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return null;

  if (!is_dir(STAXX_CFILE_TMP) && !@mkdir(STAXX_CFILE_TMP, 0700, true)) {
    $error = 'Could not create a temporary folder to copy the file into.';
    return null;
  }
  $tmp = STAXX_CFILE_TMP.'/'.bin2hex(random_bytes(8));

  $code = 1;
  $out  = staxx_sh(staxx_cfile_cp_out_cmd($container, $path, $tmp), 20, $code);
  if ($code !== 0 || !is_file($tmp)) {
    @unlink($tmp);
    $error = trim($out) !== '' ? trim($out) : 'Could not copy "'.$path.'" out of the container.';
    return null;
  }

  $size = @filesize($tmp);
  if ($size !== false && $size > STAXX_FILE_MAX) {
    @unlink($tmp);
    $error = '"'.basename($path).'" is '.ceil($size / 1024).' KiB, over the '
           . round(STAXX_FILE_MAX / 1024).' KiB limit for editing here.';
    return null;
  }

  // staxx_looks_text() is called before the temp file is removed, and its own
  // verdict is trusted rather than re-derived from $raw below — one test,
  // used everywhere a file's kind is decided.
  $isText = staxx_looks_text($tmp);
  $raw    = @file_get_contents($tmp);
  @unlink($tmp);
  if ($raw === false) { $error = 'Could not read the copied file.'; return null; }

  if ($isText) return ['text' => $raw, 'binary' => false];
  return ['b64' => base64_encode($raw), 'binary' => true];
}

/**
 * Write one file into a running container: a host temp file first, then
 * `docker cp` puts it in place — the same two-step shape staxx_write_file()
 * uses a rename() for, since a `docker cp` cannot write partially either way
 * but the temp file still means a failed copy never leaves a half-sent one
 * behind claiming to be the real thing.
 *
 * $isText does not change what is written — the bytes go down exactly as
 * sent either way, same reasoning as staxx_write_file(). It stays in the
 * signature because every caller already knows which kind it is holding.
 */
function staxx_cfile_write(string $stack, string $service, string $path, string $body, bool $isText, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return false; }

  if (strlen($body) > STAXX_FILE_MAX) {
    $error = 'That file is over the '.round(STAXX_FILE_MAX / 1024).' KiB limit for editing here.';
    return false;
  }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  if (!is_dir(STAXX_CFILE_TMP) && !@mkdir(STAXX_CFILE_TMP, 0700, true)) {
    $error = 'Could not create a temporary folder to copy the file through.';
    return false;
  }
  $tmp = STAXX_CFILE_TMP.'/'.bin2hex(random_bytes(8));
  if (@file_put_contents($tmp, $body) === false) {
    $error = 'Could not write a temporary copy of the file.';
    return false;
  }

  $code = 1;
  $out  = staxx_sh(staxx_cfile_cp_in_cmd($container, $tmp, $path), 20, $code);
  @unlink($tmp);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not copy "'.$path.'" into the container.';
    return false;
  }
  return true;
}

/** Rename (or move) one path inside a running container. */
function staxx_cfile_rename(string $stack, string $service, string $from, string $to, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($from) || !staxx_cfile_valid_path($to)) {
    $error = 'That is not a valid absolute path.';
    return false;
  }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_mv_cmd($container, $from, $to), 10, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not rename "'.$from.'" to "'.$to.'".';
    return false;
  }
  return true;
}

/**
 * Delete one path inside a running container. $recurse must be true to
 * remove a directory — see staxx_cfile_rm_cmd() for how that is enforced by
 * rm itself rather than a separate check here.
 */
function staxx_cfile_delete(string $stack, string $service, string $path, bool $recurse, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return false; }
  if ($path === '/') { $error = 'Refusing to delete the container\'s whole filesystem.'; return false; }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_rm_cmd($container, $path, $recurse), 15, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not delete "'.$path.'".';
    return false;
  }
  return true;
}

/**
 * Is this a usable owner? Numeric, "99" or "99:100".
 *
 * Split out from staxx_cfile_chown() so the endpoint can check the owner AND
 * the permissions before running either command. Checked inside the change
 * itself it was still a valid refusal, but a bad mode alongside a good owner
 * had already changed the owner by the time it was reported — a refused
 * action that changed something is worse than either outcome on its own.
 */
function staxx_cfile_valid_owner(string $owner): bool {
  return (bool)preg_match('/^[0-9]+(:[0-9]+)?$/', $owner);
}

/** Is this a usable mode? Three or four octal digits. */
function staxx_cfile_valid_mode(string $mode): bool {
  return (bool)preg_match('/^[0-7]{3,4}$/', $mode);
}

/**
 * Change who owns a path inside a running container, right through a folder.
 *
 * The owner must be numeric — "99" or "99:100". A name is refused rather than
 * passed through, because the numbers are what the listing shows in the first
 * place and a name that exists on the host need not exist inside the
 * container, where the failure would read as a mystery. Numeric-only also
 * means there is nothing in the value a shell could find interesting, which
 * escapeshellarg then guarantees a second time.
 */
function staxx_cfile_chown(string $stack, string $service, string $path, string $owner, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return false; }
  if ($path === '/') { $error = 'Refusing to change the owner of the container\'s whole filesystem.'; return false; }
  if (!staxx_cfile_valid_owner($owner)) {
    $error = 'The owner has to be a number, or a pair like 99:100 — not a name.';
    return false;
  }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_chown_cmd($container, $owner, $path), 30, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not change the owner of "'.$path.'".';
    return false;
  }
  return true;
}

/**
 * Change the permissions on a path inside a running container, right through
 * a folder.
 *
 * Octal only — "755", or four digits with a leading special bit. A symbolic
 * mode like "u+x" is refused rather than passed through: the listing shows
 * permissions in neither notation, so accepting both would mean guessing
 * which one somebody meant, and octal is the one that says exactly what the
 * result will be.
 */
function staxx_cfile_chmod(string $stack, string $service, string $path, string $mode, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return false; }
  if ($path === '/') { $error = 'Refusing to change the permissions of the container\'s whole filesystem.'; return false; }
  if (!staxx_cfile_valid_mode($mode)) {
    $error = 'Permissions have to be three or four digits from 0 to 7, like 755 — not u+x.';
    return false;
  }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_chmod_cmd($container, $mode, $path), 30, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not change the permissions of "'.$path.'".';
    return false;
  }
  return true;
}

/** Make one folder inside a running container, including any missing parents. */
function staxx_cfile_mkdir(string $stack, string $service, string $path, string &$error): bool {
  $error = '';
  if (!staxx_cfile_valid_path($path)) { $error = 'That is not a valid absolute path.'; return false; }

  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return false;

  $code = 1;
  $out  = staxx_sh(staxx_cfile_mkdir_cmd($container, $path), 10, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not create "'.$path.'".';
    return false;
  }
  return true;
}

/**
 * Where the file manager opens by default: the container's own working
 * directory, read from Docker's own image inspection rather than asked of
 * the container — an image with no shell still has this recorded, and
 * falling back to "/" matches what Docker itself does for one with none set.
 */
function staxx_cfile_home(string $stack, string $service, string &$error): string {
  $error = '';
  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return '';

  $out = trim(staxx_sh(
    escapeshellarg(staxx_docker_bin()).' inspect -f '.escapeshellarg('{{.Config.WorkingDir}}')
    . ' -- '.escapeshellarg($container),
    10
  ));
  return $out !== '' ? $out : '/';
}

/* ==================================================== PLAN_44 D6 jobs === *
 *
 * The one line above the panes (restart count and health), and the "show
 * the environment" one-press job. Both share staxx_cfile_container()'s gate
 * — the same SHELL_ENABLED switch, review lock and service-membership rule
 * the shell and file manager already stand behind — because this is that
 * same reach into a running container answering two more small questions,
 * not a capability of its own. The third D6 job, "fix ownership", never
 * reaches here at all: it only builds a command string client-side and
 * leaves it unrun in the shell, so there is nothing for the server to do.
 */

/**
 * The command a restart-count/health check actually runs, kept as its own
 * function so a test can assert on the string without a real container to
 * point it at — see tests/server/console.php.
 *
 * The format flag MUST sit before the `--`: written the other way round, the
 * `--` swallows it and the whole JSON document comes back instead of the two
 * values asked for. Measured on the server, the hard way.
 */
function staxx_cstat_cmd(string $container): string {
  $fmt = '{{.RestartCount}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}';
  return escapeshellarg(staxx_docker_bin()).' inspect --format '.escapeshellarg($fmt)
       . ' -- '.escapeshellarg($container);
}

/**
 * Restart count and health status for the container behind one service —
 * the "how long has it been up" half of D6's line comes free out of the
 * state snapshot the browser already holds (docker's own status text), so
 * this is only the one figure that snapshot does not carry.
 *
 * @return array{restarts:int, health:string}|null
 */
function staxx_cstat(string $stack, string $service, string &$error): ?array {
  $error = '';
  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return null;

  $code = 1;
  $out  = staxx_sh(staxx_cstat_cmd($container), 10, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not read this container\'s restart count.';
    return null;
  }

  [$restarts, $health] = array_pad(explode('|', trim($out), 2), 2, '');
  return ['restarts' => (int)$restarts, 'health' => $health !== '' ? $health : 'none'];
}

/** See staxx_cstat_cmd()'s own reasoning for keeping the builder separate. */
function staxx_cenv_cmd(string $container): string {
  return escapeshellarg(staxx_docker_bin()).' exec '.escapeshellarg($container).' env';
}

/**
 * `env` inside the running container behind one service — read-only, and the
 * browser never names the container itself, same as every other exec-based
 * action in this file.
 */
function staxx_cenv(string $stack, string $service, string &$error): ?string {
  $error = '';
  $container = staxx_cfile_container($stack, $service, $error);
  if ($container === '') return null;

  $code = 1;
  $out  = staxx_sh(staxx_cenv_cmd($container), 10, $code);
  if ($code !== 0) {
    $error = trim($out) !== '' ? trim($out) : 'Could not read the environment inside this container.';
    return null;
  }
  return $out;
}
?>
