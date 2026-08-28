<?PHP
/* StaXX — folders for organising stacks.
 * Copyright 2026, StaXX contributors.
 *
 * A FOLDER IS A DIRECTORY. There is no index, no ids, no membership file.
 * A stack at <root>/Media/jellyfin/ is in the folder "Media" because that is
 * where it is. Creating a folder is mkdir, renaming one is mv, and moving a
 * stack between folders is moving its directory.
 *
 * This used to be a separate presentational layer with its own folders.json
 * holding generated ids and a stack-name-to-folder map. That was the one index
 * in a project whose stated model is "a stack is a directory containing a
 * compose file, and nothing else — no database, no index, no metadata file",
 * and it showed: the modal said "Folder" meaning the directory the compose file
 * sat in, while the list said "folder" meaning something else entirely. Now
 * they are the same thing.
 *
 * What remains here is the one piece of it that genuinely has nowhere else to
 * live: whether a folder is shown collapsed. That is a note about the view and
 * not about the stacks, an empty folder still needs somewhere to keep it, and
 * it is stored on the server rather than in the browser so the layout is the
 * same on every device you open the page on.
 *
 * Folders are still one level deep. See staxx_scan_stacks().
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_FOLDERS_LOADED')) return;
define('STAXX_FOLDERS_LOADED', true);

/**
 * Where folders.json lives: <store>/config/folders.json, or '' when no data
 * store has been chosen yet — a function rather than a constant because
 * that answer can change, and every caller below checks for '' rather than
 * building a path out of it, which would otherwise be a real,
 * writable-looking path at the root of the filesystem.
 */
function staxx_folders_file(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/folders.json';
}

/**
 * The stored view state.
 *
 * Shape:
 *   version    int
 *   collapsed  map of folder name => true
 *   start      the boot/display order block — see staxx_start_defaults()
 *
 * Only the collapsed ones are listed, so a folder nobody has touched needs no
 * entry and deleting this file loses nothing but which folders were shut.
 *
 * NOTE ON REMOVED FIELDS: this file used to carry `folders` (generated ids and
 * display names) and `assign` (stack name => folder id). Both are gone — the
 * directory tree answers those questions now. An older file's keys are simply
 * ignored, and because staxx_folders_save() writes back only what this
 * function returns, they are dropped the next time anything saves. That is a
 * one-way door and is intended, but worth being able to find.
 *
 * VERSION 3 adds `start`: the order things are shown and started in, plus
 * their boot waits (see PLAN_43). A version 1 or 2 file simply has no `start`
 * key, so staxx_start_normalise() fills in the defaults and everything looks
 * and behaves exactly as it did before — nothing to migrate.
 *
 * @return array{version:int, collapsed:array<string,bool>, start:array}
 */
function staxx_folders_load(bool $fresh = false): array {
  // Cached for the request: staxx_stack_children() asks for the start order
  // once per stack, and a 40-stack page has no business reading and decoding
  // the same small file 40 times. $fresh re-reads, which is how
  // staxx_folders_save() keeps the cache honest after a write.
  static $cache = null;
  if ($cache !== null && !$fresh) return $cache;

  $empty = ['version' => 3, 'collapsed' => [], 'start' => staxx_start_defaults()];

  $file = staxx_folders_file();
  if ($file === '' || !is_file($file)) return $cache = $empty;

  $data = json_decode((string)@file_get_contents($file), true);
  if (!is_array($data)) return $cache = $empty;

  $collapsed = [];

  // Version 1 kept the flag inside each folder record, alongside an id we no
  // longer have any use for. Read the names across so an existing install does
  // not open with every folder flung open.
  foreach ((array)($data['folders'] ?? []) as $f) {
    if (!is_array($f)) continue;
    $name = trim((string)($f['name'] ?? ''));
    if ($name !== '' && !empty($f['collapsed'])) $collapsed[$name] = true;
  }

  foreach ((array)($data['collapsed'] ?? []) as $name => $on) {
    if (is_string($name) && $on) $collapsed[$name] = true;
  }

  return $cache = [
    'version'   => 3,
    'collapsed' => $collapsed,
    'start'     => staxx_start_normalise($data['start'] ?? []),
  ];
}

/**
 * Write folders.json, the whole document — temp-then-rename so a reader
 * never sees a half-written file, and json_encode() checked for failure
 * rather than trusted, since an unchecked one would write a near-empty "\n"
 * over every collapsed flag, drag order and boot wait already on disk.
 *
 * Does NOT lock. Every caller reaches this already holding the lock
 * staxx_folders_update() below takes on this file's actual path, which is
 * how every mutator in this file gets here. Locking a second time in here as
 * well would just make this call wait out its own caller's lock and then
 * time out.
 */
function staxx_folders_save(array $data, ?string &$error = null): bool {
  $error = '';
  $file = staxx_folders_file();
  if ($file === '') {
    $error = 'StaXX has nowhere to keep the folder layout yet — choose where its data should live first.';
    return false;
  }

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir;
    return false;
  }

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    $error = 'Could not encode the folder layout, so nothing was written.';
    return false;
  }

  $tmp = $file.'.'.getmypid().'.tmp';
  $written = @file_put_contents($tmp, $json."\n");
  if ($written === false || $written !== strlen($json) + 1) {
    @unlink($tmp);
    $error = 'Could not write '.$file;
    return false;
  }
  if (!@rename($tmp, $file)) {
    @unlink($tmp);
    $error = 'Could not save '.$file.' — the temporary file could not be put in place.';
    return false;
  }

  staxx_folders_load(true);   // the cached copy is now a lie
  return true;
}

/**
 * Run one change to folders.json under a single lock spanning both the read
 * and the write — the atomic-mkdir trick staxx_update_lock() uses for its own,
 * longer-lived pass, held here just long enough that a second save started
 * while this one is still in flight waits for it, rather than starting from
 * the same stale read and silently overwriting it once both finish. $mutate
 * is handed the freshly-reloaded data (never the request-cached copy) and
 * returns what should be saved.
 */
function staxx_folders_update(callable $mutate, ?string &$error = null): bool {
  $error = '';
  $file = staxx_folders_file();
  if ($file === '') {
    $error = 'StaXX has nowhere to keep the folder layout yet — choose where its data should live first.';
    return false;
  }
  if (!staxx_mkdir_lock($file, $error)) return false;

  $data = $mutate(staxx_folders_load(true));
  $ok   = staxx_folders_save($data, $error);

  staxx_mkdir_unlock($file);
  return $ok;
}

/* --------------------------------------------------------- start order -- */

/** The shape of the `start` block when nothing has ever touched it. */
function staxx_start_defaults(): array {
  return [
    'folders'  => [],
    'stacks'   => [],
    'services' => [],
    'delay'    => [],
    'seen'     => '',
    'pending'  => false,
  ];
}

/**
 * Coerce whatever was read back from disk into the exact shape above, every
 * key present and of the right type. Anything foreign (a stray scalar where a
 * list belongs, a non-numeric delay) is dropped rather than trusted — this is
 * the one gate between a hand-edited or corrupted file and the rest of the
 * plugin, so callers never have to guard against a missing key again.
 */
function staxx_start_normalise($raw): array {
  $raw = is_array($raw) ? $raw : [];
  $out = staxx_start_defaults();

  if (is_array($raw['folders'] ?? null)) {
    $out['folders'] = array_values(array_filter($raw['folders'], 'is_string'));
  }

  foreach (['stacks', 'services'] as $key) {
    if (!is_array($raw[$key] ?? null)) continue;
    foreach ($raw[$key] as $parent => $list) {
      if (is_string($parent) && is_array($list)) {
        $out[$key][$parent] = array_values(array_filter($list, 'is_string'));
      }
    }
  }

  if (is_array($raw['delay'] ?? null)) {
    foreach ($raw['delay'] as $key => $secs) {
      // Only non-zero values are kept — a zero-wait entry means nothing and
      // would otherwise accumulate forever as rows are dragged around.
      if (is_string($key) && is_numeric($secs) && (int)$secs !== 0) {
        $out['delay'][$key] = (int)$secs;
      }
    }
  }

  if (is_string($raw['seen'] ?? null)) $out['seen'] = $raw['seen'];
  $out['pending'] = !empty($raw['pending']);

  return $out;
}

/** The `start` block alone, normalised — the common case for a reader. */
function staxx_start_load(): array {
  return staxx_folders_load()['start'];
}

/** Replace the `start` block and save, leaving `collapsed` untouched. */
function staxx_start_store(array $start, ?string &$error = null): bool {
  return staxx_folders_update(function (array $data) use ($start) {
    $data['start'] = staxx_start_normalise($start);
    return $data;
  }, $error);
}

/**
 * Mutate the `start` block under the same lock the write itself takes:
 * $mutate is handed the freshly-reloaded, normalised block and returns what
 * it should become. staxx_start_store() alone cannot close this race for a
 * caller that read the block with staxx_start_load() before taking any
 * lock — that read can already be stale by the time a save follows it, so a
 * drag order saved from one tab and a boot-wait saved from another can have
 * the second overwrite the first's change rather than merge with it. Every
 * mutator that changes the `start` block from a fresh read should call this
 * instead of staxx_start_load() + staxx_start_store().
 */
function staxx_start_update(callable $mutate, ?string &$error = null): bool {
  return staxx_folders_update(function (array $data) use ($mutate) {
    $data['start'] = staxx_start_normalise($mutate(staxx_start_normalise($data['start'] ?? [])));
    return $data;
  }, $error);
}

/**
 * Arrange $names by $order: everything $order mentions, in that order, then
 * everything else in the order it already arrived in. A name in $order that
 * is not in $names is simply skipped — a stack can vanish from view for a
 * refresh (compose down, an import mid-flight) without losing its place once
 * it is back, which is why nothing here prunes $order itself.
 */
function staxx_start_sort(array $names, array $order): array {
  $remaining = array_flip($names);
  $result    = [];

  foreach ($order as $name) {
    if (is_string($name) && isset($remaining[$name])) {
      $result[] = $name;
      unset($remaining[$name]);
    }
  }
  foreach ($names as $name) {
    if (isset($remaining[$name])) $result[] = $name;
  }
  return $result;
}

/** Is this a name compose would accept for a service? */
function staxx_start_valid_service_name(string $name): bool {
  return $name !== '' && strlen($name) <= 63 && (bool)preg_match('/^[A-Za-z0-9._-]+$/', $name);
}

/**
 * Save one sibling group's order after a drag: every folder, every folder's
 * stacks (or the ungrouped top level, $parent === ''), or one stack's
 * services. Refuses the whole call on any bad name — never writes a partial
 * list — because a partly-applied drag is worse than one that visibly failed.
 */
function staxx_start_order_set(string $scope, string $parent, array $names, string &$error): bool {
  $error = '';

  if (!in_array($scope, ['folders', 'stacks', 'services'], true)) {
    $error = 'That is not something StaXX knows how to order.';
    return false;
  }
  if ($scope === 'folders' && $parent !== '') {
    $error = 'Folders have no parent to be ordered within.';
    return false;
  }
  // Checked against real folders on disk, not just against the naming rule —
  // a name that merely looks valid but does not exist would otherwise be
  // accepted here and quietly grow a "stacks" entry for a folder nobody can
  // ever see or clear.
  if ($scope === 'stacks' && $parent !== '' && !in_array($parent, staxx_folder_names(), true)) {
    $error = 'No such folder.';
    return false;
  }
  if ($scope === 'services' && !staxx_valid_path($parent)) {
    $error = 'Invalid stack name.';
    return false;
  }

  foreach ($names as $name) {
    $valid = is_string($name) && (
      $scope === 'services' ? staxx_start_valid_service_name($name) : staxx_valid_name($name)
    );
    if (!$valid) {
      $error = $scope === 'services'
        ? 'Service names may only contain letters, numbers, dots, dashes and underscores, '
        . 'and must be 63 characters or fewer.'
        : staxx_folder_name_rule();
      return false;
    }
  }

  $names = array_values($names);

  return staxx_start_update(function (array $start) use ($scope, $parent, $names): array {
    if ($scope === 'folders')    $start['folders']          = $names;
    elseif ($scope === 'stacks') $start['stacks'][$parent]   = $names;
    else                         $start['services'][$parent] = $names;
    return $start;
  }, $error);
}

/**
 * Re-parent every start-block entry under one stack or folder path, when a
 * rename or a move changes it. Matches a services-map key or a delay key's
 * embedded path exactly (a single stack moving or being renamed) or as a
 * "$from/" prefix (a whole folder moving, carrying every stack beneath it).
 *
 * $to === '' means "drop the prefix" rather than "delete" — used when a
 * folder is dissolved and its stacks fold back to the top level, so
 * "Services/npm" becomes "npm" rather than vanishing. Deleting a stack
 * outright is staxx_start_drop()'s job, not this one, because unfolding a
 * single stack's own services to the top level (rather than removing them)
 * would collide with whatever is already down there.
 *
 * A folder-level delay key ("folder:NAME") only ever matches $from exactly,
 * and is simply dropped when $to is '' — there is no top-level equivalent of
 * a folder.
 */
function staxx_start_rekey(array &$start, string $from, string $to): void {
  $rename = function (string $path) use ($from, $to): ?string {
    if ($path === $from) return $to === '' ? null : $to;
    if (str_starts_with($path, $from.'/')) {
      $tail = substr($path, strlen($from) + 1);
      return $to === '' ? $tail : $to.'/'.$tail;
    }
    return $path;
  };

  $services = [];
  foreach ($start['services'] as $key => $order) {
    $newKey = $rename($key);
    if ($newKey !== null) $services[$newKey] = $order;
  }
  $start['services'] = $services;

  $delay = [];
  foreach ($start['delay'] as $key => $secs) {
    $at = strpos($key, ':');
    if ($at === false) { $delay[$key] = $secs; continue; }

    $level = substr($key, 0, $at);
    $path  = substr($key, $at + 1);

    if ($level === 'folder') {
      if ($path !== $from) $delay[$key] = $secs;
      elseif ($to !== '') $delay['folder:'.$to] = $secs;
      continue;
    }

    $newPath = $rename($path);
    if ($newPath !== null) $delay[$level.':'.$newPath] = $secs;
  }
  $start['delay'] = $delay;
}

/** Drop every start-block entry that belongs to one stack, root and branch. */
function staxx_start_drop(array &$start, string $stack): void {
  unset($start['services'][$stack]);

  foreach (array_keys($start['delay']) as $key) {
    $at = strpos($key, ':');
    if ($at === false) continue;
    $level = substr($key, 0, $at);
    $path  = substr($key, $at + 1);
    if ($level === 'folder') continue;
    if ($path === $stack || str_starts_with($path, $stack.'/')) unset($start['delay'][$key]);
  }
}

/** Remove one leaf name from a stacks-order list, if it is there at all. */
function staxx_start_list_remove(array $list, string $leaf): array {
  $pos = array_search($leaf, $list, true);
  if ($pos !== false) array_splice($list, $pos, 1);
  return $list;
}

/**
 * A folder's name is now a directory name, so it has to survive being one.
 * The same gate every stack name goes through, for the same reason.
 */
function staxx_folder_valid_name(string $name): bool {
  return staxx_valid_name(trim($name));
}

/** The message shown whenever a folder name is refused. */
function staxx_folder_name_rule(): string {
  return 'Folder names may contain letters, numbers, dots, dashes and underscores, '
       . 'must start with a letter or number, and must be 63 characters or fewer. '
       . 'A folder is a real directory on disk now, so its name has to be one too.';
}

/** Is anything already using this name at the top of the stack root? */
function staxx_folder_taken(string $name, ?string &$what = null): bool {
  $scan = staxx_scan_stacks();

  foreach ($scan['folders'] as $f) {
    if (strcasecmp($f, $name) === 0) { $what = 'folder'; return true; }
  }
  foreach ($scan['stacks'] as $s) {
    if ($s['folder'] === '' && strcasecmp($s['leaf'], $name) === 0) {
      $what = 'stack';
      return true;
    }
  }
  return false;
}

function staxx_folder_create(string $name, string &$error): string {
  $error = '';
  $name  = trim($name);

  if (!staxx_folder_valid_name($name)) { $error = staxx_folder_name_rule(); return ''; }

  $what = '';
  if (staxx_folder_taken($name, $what)) {
    $error = 'There is already a '.$what.' called "'.$name.'".';
    return '';
  }

  $dir = staxx_stack_root().'/'.$name;
  if (!@mkdir($dir, 0755, true)) { $error = 'Could not create '.$dir; return ''; }

  // The tree's shape just changed on disk; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();

  return $name;
}

/**
 * Rename a folder. This moves the directory, and with it every stack inside —
 * which does not disturb any of them, because a compose project is named after
 * the directory holding its file and that name is not the one changing.
 */
function staxx_folder_rename(string $from, string $to, string &$error): bool {
  $error = '';
  $to    = trim($to);

  if (!staxx_folder_valid_name($from)) { $error = 'No such folder.'; return false; }
  if (!staxx_folder_valid_name($to))   { $error = staxx_folder_name_rule(); return false; }
  if ($from === $to) return true;

  $root = staxx_stack_root();
  if (!is_dir($root.'/'.$from)) { $error = 'No such folder.'; return false; }

  $what = '';
  if (strcasecmp($from, $to) !== 0 && staxx_folder_taken($to, $what)) {
    $error = 'There is already a '.$what.' called "'.$to.'".';
    return false;
  }

  if (!@rename($root.'/'.$from, $root.'/'.$to)) {
    $error = 'Could not rename the folder on disk.';
    return false;
  }

  // The tree's shape just changed on disk; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();

  staxx_folders_update(function (array $data) use ($from, $to): array {
    if (!empty($data['collapsed'][$from])) {
      unset($data['collapsed'][$from]);
      $data['collapsed'][$to] = true;
    }

    $start = $data['start'];
    $idx = array_search($from, $start['folders'], true);
    if ($idx !== false) $start['folders'][$idx] = $to;
    if (isset($start['stacks'][$from])) {
      $start['stacks'][$to] = $start['stacks'][$from];
      unset($start['stacks'][$from]);
    }
    staxx_start_rekey($start, $from, $to);
    $data['start'] = $start;

    return $data;
  });
  return true;
}

/**
 * Remove a folder, moving anything inside it back to the top level first.
 *
 * A folder is still a label as far as the user is concerned, and deleting a
 * label must never delete the thing it was attached to. The difference now is
 * that "returning to the ungrouped list" is a real move on disk.
 *
 * Nothing is moved unless everything can be: a half-emptied folder is worse
 * than a refusal, and a name collision at the top level is exactly the case
 * where stopping is the right answer.
 */
function staxx_folder_delete(string $name, string &$error): bool {
  $error = '';
  if (!staxx_folder_valid_name($name)) { $error = 'No such folder.'; return false; }

  $root = staxx_stack_root();
  $dir  = $root.'/'.$name;
  if (!is_dir($dir)) { $error = 'No such folder.'; return false; }

  $scan    = staxx_scan_stacks();
  $members = array_values(array_filter($scan['stacks'], fn($s) => $s['folder'] === $name));

  $clash = [];
  foreach ($members as $m) {
    $what = '';
    if (staxx_folder_taken($m['leaf'], $what)) $clash[] = $m['leaf'];
  }
  if ($clash) {
    $error = 'Nothing was moved. These would collide with something already at the top level: '
           . implode(', ', $clash) . '. Rename them first.';
    return false;
  }

  // Checked BEFORE anything moves, not after: the docblock promises nothing
  // is moved unless everything can be, and running this once every member is
  // already gone can only describe what is left, never what was really here
  // to begin with.
  $memberLeaves = array_map(fn($m) => $m['leaf'], $members);
  $left = array_values(array_diff((array)@scandir($dir), ['.', '..'], $memberLeaves));
  if ($left) {
    $error = 'Nothing was moved. The folder also contains: '
           . implode(', ', array_slice($left, 0, 6)) . (count($left) > 6 ? ', …' : '')
           . '. Remove it by hand first, or move the stacks out yourself.';
    return false;
  }

  $moved      = [];
  $failedLeaf = '';
  foreach ($members as $m) {
    if (@rename($m['dir'], $root.'/'.$m['leaf'])) { $moved[] = $m['leaf']; continue; }
    $failedLeaf = $m['leaf'];
    break;
  }
  if ($moved) staxx_scan_stacks_reset(); // stacks left the folder on disk

  // Bookkeeping runs regardless of whether anything actually needed moving
  // (an empty folder has none), and for whatever DID move even when a
  // sibling did not — a stack that really is sitting at the top level now
  // must never be left pointing at a folder that no longer holds it, even
  // when the folder itself survives because a sibling could not be moved.
  staxx_folders_update(function (array $data) use ($name, $moved, $failedLeaf): array {
    $start = $data['start'];
    foreach ($moved as $leaf) staxx_start_rekey($start, $name.'/'.$leaf, $leaf);
    if ($moved) {
      $ordered = staxx_start_sort($moved, $start['stacks'][$name] ?? []);
      $start['stacks'][''] = array_values(array_merge($start['stacks'][''] ?? [], $ordered));
    }

    if ($failedLeaf === '') {
      unset($start['stacks'][$name]);
      $idx = array_search($name, $start['folders'], true);
      if ($idx !== false) array_splice($start['folders'], $idx, 1);
      unset($data['collapsed'][$name]);
    } else {
      // A sibling is still in the folder, so its own order entry has to
      // survive — just without the leaves that already left.
      $start['stacks'][$name] = array_values(array_diff($start['stacks'][$name] ?? [], $moved));
    }

    $data['start'] = $start;
    return $data;
  });

  if ($failedLeaf !== '') {
    $error = 'Moved what it could, but "'.$failedLeaf.'" could not be moved out of the folder. '
           . 'The folder has been left in place.';
    return false;
  }

  if (!@rmdir($dir)) { $error = 'Could not remove the folder '.$dir; return false; }

  // The tree's shape just changed on disk; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();

  return true;
}

/**
 * Move a stack into a folder, or pass an empty folder to move it back to the
 * top level. This is a directory move, so the stack's identity changes with it
 * — "jellyfin" becomes "Media/jellyfin" — and the caller gets the new one back.
 *
 * Its containers are not disturbed. A compose project is named after the
 * directory holding its file, and that directory is the one being moved rather
 * than renamed, so the project name is the same on the other side. The plugin
 * finds them again by that name until the next start restamps the path.
 */
function staxx_folder_assign(string $stack, string $folder, string &$error): string {
  $error  = '';
  $folder = trim($folder);

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }
  if ($folder !== '' && !staxx_folder_valid_name($folder)) {
    $error = 'No such folder.';
    return '';
  }

  $root = staxx_stack_root();
  $from = staxx_stack_dir($stack);
  if (!is_dir($from)) { $error = 'No such stack.'; return ''; }

  $leaf = staxx_path_leaf($stack);
  $rel  = $folder === '' ? $leaf : $folder.'/'.$leaf;
  if ($rel === $stack) return $stack;

  if ($folder !== '' && !is_dir($root.'/'.$folder)) { $error = 'No such folder.'; return ''; }

  // Case-insensitive, the same rule staxx_folder_taken() already uses for a
  // brand new folder or stack: staxx_project_name() lowercases, so
  // "Media/Jellyfin" moving in beside an existing "Media/jellyfin" would give
  // both stacks the same compose project the moment either one started,
  // regardless of what the filesystem itself is willing to hold side by side.
  if ($folder === '') {
    $what = '';
    if (staxx_folder_taken($leaf, $what)) {
      $error = 'There is already a '.$what.' called "'.$leaf.'" at the top level.';
      return '';
    }
  } else {
    foreach (staxx_scan_stacks()['stacks'] as $s) {
      if ($s['folder'] === $folder && strcasecmp($s['leaf'], $leaf) === 0) {
        $error = 'There is already a stack called "'.$leaf.'" in "'.$folder.'".';
        return '';
      }
    }
  }

  // The checks above only know about stacks and folders. A stray empty
  // directory is neither, and rename() onto an empty directory *succeeds* on
  // Linux — so this still catches it, same reasoning as staxx_rename_stack().
  $to = $root.'/'.$rel;
  if (file_exists($to)) {
    $error = 'There is already something called "'.$leaf.'" '
           . ($folder === '' ? 'at the top level.' : 'in "'.$folder.'".');
    return '';
  }

  if (!@rename($from, $to)) { $error = 'Could not move the stack on disk.'; return ''; }

  // The tree's shape just changed on disk; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();

  staxx_folders_update(function (array $data) use ($stack, $rel, $folder, $leaf): array {
    $start = $data['start'];

    $oldFolder = staxx_path_folder($stack);
    $start['stacks'][$oldFolder] = staxx_start_list_remove($start['stacks'][$oldFolder] ?? [], $leaf);
    $newList = $start['stacks'][$folder] ?? [];
    $newList[] = $leaf;
    $start['stacks'][$folder] = $newList;

    staxx_start_rekey($start, $stack, $rel);
    $data['start'] = $start;
    return $data;
  });

  return $rel;
}

function staxx_folder_collapse(string $name, bool $collapsed, string &$error): bool {
  $error = '';
  if (!staxx_folder_valid_name($name)) { $error = 'No such folder.'; return false; }

  return staxx_folders_update(function (array $data) use ($name, $collapsed): array {
    if ($collapsed) $data['collapsed'][$name] = true;
    else unset($data['collapsed'][$name]);
    return $data;
  }, $error);
}

/**
 * Arrange stacks into their folders for rendering.
 *
 * Returns a flat list of rows in display order, because that is what a table
 * needs. Each row is either a folder heading or a stack. A folder's `id` is its
 * name — there is nothing else it could be now, and the two were only ever
 * separate so that renaming a folder did not have to touch anything.
 *
 * @param  array $stacks from staxx_list_stacks()
 * @return array<int, array{type:string, ...}>
 */
function staxx_folder_layout(array $stacks): array {
  $data  = staxx_folders_load();
  $start = $data['start'];
  $rows  = [];

  // Every folder on disk, including the empty ones — an empty folder you just
  // made must appear, or it looks as though the button did nothing. Ordered by
  // the stored drag order, folders nobody has dragged falling back to
  // whatever staxx_folder_names() already returns them in.
  foreach (staxx_start_sort(staxx_folder_names(), $start['folders']) as $folder) {
    $members = array_values(array_filter($stacks, fn($s) => ($s['folder'] ?? '') === $folder));

    // Sorted by leaf name — the folder's stored order — not by the whole
    // stack record, which is why the map goes leaf => record and back.
    $byLeaf = [];
    foreach ($members as $m) $byLeaf[$m['leaf']] = $m;
    $order = staxx_start_sort(array_keys($byLeaf), $start['stacks'][$folder] ?? []);
    $members = array_map(fn($leaf) => $byLeaf[$leaf], $order);

    $running = 0;
    foreach ($members as $m) if ($m['running']) $running++;

    $collapsed = !empty($data['collapsed'][$folder]);

    $rows[] = [
      'type'      => 'folder',
      'id'        => $folder,
      'name'      => $folder,
      'collapsed' => $collapsed,
      'count'     => count($members),
      'running'   => $running,
    ];

    // 'expanded' is forced to false — always collapsed on load, by the user's
    // choice. javascript/stacks.js keeps its own in-memory record so that a
    // mid-session refresh does not slam shut everything that was open.
    foreach ($members as $m) {
      $rows[] = ['type' => 'stack', 'folder' => $folder,
                 'hidden' => $collapsed, 'stack' => $m,
                 'expanded' => false];
    }
  }

  $top = array_values(array_filter($stacks, fn($s) => ($s['folder'] ?? '') === ''));
  $byLeaf = [];
  foreach ($top as $s) $byLeaf[$s['leaf']] = $s;
  $order = staxx_start_sort(array_keys($byLeaf), $start['stacks'][''] ?? []);

  foreach ($order as $leaf) {
    $rows[] = ['type' => 'stack', 'folder' => '', 'hidden' => false, 'stack' => $byLeaf[$leaf],
               'expanded' => false];
  }

  return $rows;
}
?>
