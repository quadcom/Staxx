<?PHP
/* Stack Manager — folders for organising stacks.
 * Copyright 2026, Stack Manager contributors.
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
 * Folders are still one level deep. See stackman_scan_stacks().
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/stack.manager/include/Stacks.php';

if (defined('STACKMAN_FOLDERS_FILE')) return;

define('STACKMAN_FOLDERS_FILE', STACKMAN_CFG_DIR.'/folders.json');

/**
 * The stored view state.
 *
 * Shape:
 *   version    int
 *   collapsed  map of folder name => true
 *
 * Only the collapsed ones are listed, so a folder nobody has touched needs no
 * entry and deleting this file loses nothing but which folders were shut.
 *
 * NOTE ON REMOVED FIELDS: this file used to carry `folders` (generated ids and
 * display names) and `assign` (stack name => folder id). Both are gone — the
 * directory tree answers those questions now. An older file's keys are simply
 * ignored, and because stackman_folders_save() writes back only what this
 * function returns, they are dropped the next time anything saves. That is a
 * one-way door and is intended, but worth being able to find.
 *
 * @return array{version:int, collapsed:array<string,bool>}
 */
function stackman_folders_load(): array {
  $empty = ['version' => 2, 'collapsed' => []];

  if (!is_file(STACKMAN_FOLDERS_FILE)) return $empty;

  $data = json_decode((string)@file_get_contents(STACKMAN_FOLDERS_FILE), true);
  if (!is_array($data)) return $empty;

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

  return ['version' => 2, 'collapsed' => $collapsed];
}

function stackman_folders_save(array $data, string &$error = null): bool {
  $error = '';
  if (!is_dir(STACKMAN_CFG_DIR) && !@mkdir(STACKMAN_CFG_DIR, 0755, true)) {
    $error = 'Could not create '.STACKMAN_CFG_DIR;
    return false;
  }
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if (@file_put_contents(STACKMAN_FOLDERS_FILE, $json."\n") === false) {
    $error = 'Could not write '.STACKMAN_FOLDERS_FILE;
    return false;
  }
  return true;
}

/**
 * A folder's name is now a directory name, so it has to survive being one.
 * The same gate every stack name goes through, for the same reason.
 */
function stackman_folder_valid_name(string $name): bool {
  return stackman_valid_name(trim($name));
}

/** The message shown whenever a folder name is refused. */
function stackman_folder_name_rule(): string {
  return 'Folder names may contain letters, numbers, dots, dashes and underscores, '
       . 'must start with a letter or number, and must be 63 characters or fewer. '
       . 'A folder is a real directory on disk now, so its name has to be one too.';
}

/** Is anything already using this name at the top of the stack root? */
function stackman_folder_taken(string $name, string &$what = null): bool {
  $scan = stackman_scan_stacks();

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

function stackman_folder_create(string $name, string &$error): string {
  $error = '';
  $name  = trim($name);

  if (!stackman_folder_valid_name($name)) { $error = stackman_folder_name_rule(); return ''; }

  $what = '';
  if (stackman_folder_taken($name, $what)) {
    $error = 'There is already a '.$what.' called "'.$name.'".';
    return '';
  }

  $dir = stackman_stack_root().'/'.$name;
  if (!@mkdir($dir, 0755, true)) { $error = 'Could not create '.$dir; return ''; }

  return $name;
}

/**
 * Rename a folder. This moves the directory, and with it every stack inside —
 * which does not disturb any of them, because a compose project is named after
 * the directory holding its file and that name is not the one changing.
 */
function stackman_folder_rename(string $from, string $to, string &$error): bool {
  $error = '';
  $to    = trim($to);

  if (!stackman_folder_valid_name($from)) { $error = 'No such folder.'; return false; }
  if (!stackman_folder_valid_name($to))   { $error = stackman_folder_name_rule(); return false; }
  if ($from === $to) return true;

  $root = stackman_stack_root();
  if (!is_dir($root.'/'.$from)) { $error = 'No such folder.'; return false; }

  $what = '';
  if (strcasecmp($from, $to) !== 0 && stackman_folder_taken($to, $what)) {
    $error = 'There is already a '.$what.' called "'.$to.'".';
    return false;
  }

  if (!@rename($root.'/'.$from, $root.'/'.$to)) {
    $error = 'Could not rename the folder on disk.';
    return false;
  }

  $data = stackman_folders_load();
  if (!empty($data['collapsed'][$from])) {
    unset($data['collapsed'][$from]);
    $data['collapsed'][$to] = true;
    stackman_folders_save($data);
  }
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
function stackman_folder_delete(string $name, string &$error): bool {
  $error = '';
  if (!stackman_folder_valid_name($name)) { $error = 'No such folder.'; return false; }

  $root = stackman_stack_root();
  $dir  = $root.'/'.$name;
  if (!is_dir($dir)) { $error = 'No such folder.'; return false; }

  $scan    = stackman_scan_stacks();
  $members = array_values(array_filter($scan['stacks'], fn($s) => $s['folder'] === $name));

  $clash = [];
  foreach ($members as $m) {
    $what = '';
    if (stackman_folder_taken($m['leaf'], $what)) $clash[] = $m['leaf'];
  }
  if ($clash) {
    $error = 'Nothing was moved. These would collide with something already at the top level: '
           . implode(', ', $clash) . '. Rename them first.';
    return false;
  }

  foreach ($members as $m) {
    if (@rename($m['dir'], $root.'/'.$m['leaf'])) continue;
    $error = 'Moved what it could, but "'.$m['leaf'].'" could not be moved out of the folder. '
           . 'The folder has been left in place.';
    return false;
  }

  // Anything still in there is something this plugin did not put there and does
  // not understand, so say what is in the way rather than deleting it.
  $left = array_values(array_diff((array)@scandir($dir), ['.', '..']));
  if ($left) {
    $error = 'The stacks were moved out, but the folder still contains: '
           . implode(', ', array_slice($left, 0, 6)) . (count($left) > 6 ? ', …' : '')
           . '. Remove it by hand if you are sure.';
    return false;
  }

  if (!@rmdir($dir)) { $error = 'Could not remove the folder '.$dir; return false; }

  $data = stackman_folders_load();
  unset($data['collapsed'][$name]);
  stackman_folders_save($data);
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
function stackman_folder_assign(string $stack, string $folder, string &$error): string {
  $error  = '';
  $folder = trim($folder);

  if (!stackman_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }
  if ($folder !== '' && !stackman_folder_valid_name($folder)) {
    $error = 'No such folder.';
    return '';
  }

  $root = stackman_stack_root();
  $from = stackman_stack_dir($stack);
  if (!is_dir($from)) { $error = 'No such stack.'; return ''; }

  $leaf = stackman_path_leaf($stack);
  $rel  = $folder === '' ? $leaf : $folder.'/'.$leaf;
  if ($rel === $stack) return $stack;

  if ($folder !== '' && !is_dir($root.'/'.$folder)) { $error = 'No such folder.'; return ''; }

  $to = $root.'/'.$rel;
  if (file_exists($to)) {
    $error = 'There is already something called "'.$leaf.'" '
           . ($folder === '' ? 'at the top level.' : 'in "'.$folder.'".');
    return '';
  }

  if (!@rename($from, $to)) { $error = 'Could not move the stack on disk.'; return ''; }
  return $rel;
}

function stackman_folder_collapse(string $name, bool $collapsed, string &$error): bool {
  $error = '';
  if (!stackman_folder_valid_name($name)) { $error = 'No such folder.'; return false; }

  $data = stackman_folders_load();
  if ($collapsed) $data['collapsed'][$name] = true;
  else unset($data['collapsed'][$name]);

  return stackman_folders_save($data, $error);
}

/**
 * Arrange stacks into their folders for rendering.
 *
 * Returns a flat list of rows in display order, because that is what a table
 * needs. Each row is either a folder heading or a stack. A folder's `id` is its
 * name — there is nothing else it could be now, and the two were only ever
 * separate so that renaming a folder did not have to touch anything.
 *
 * @param  array $stacks from stackman_list_stacks()
 * @return array<int, array{type:string, ...}>
 */
function stackman_folder_layout(array $stacks): array {
  $data = stackman_folders_load();
  $rows = [];

  // Every folder on disk, including the empty ones — an empty folder you just
  // made must appear, or it looks as though the button did nothing.
  foreach (stackman_folder_names() as $folder) {
    $members = array_values(array_filter($stacks, fn($s) => ($s['folder'] ?? '') === $folder));

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

  foreach ($stacks as $s) {
    if (($s['folder'] ?? '') === '') {
      $rows[] = ['type' => 'stack', 'folder' => '', 'hidden' => false, 'stack' => $s,
                 'expanded' => false];
    }
  }

  return $rows;
}
?>
