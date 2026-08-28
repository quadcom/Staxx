<?PHP
/* StaXX — compose-native Docker management for Unraid.
 * Copyright 2026, StaXX contributors.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
if (defined('STAXX_STORE_PHP')) return;
define('STAXX_STORE_PHP', true);

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

// How many stack names staxx_store_inspect() lists by name before it just
// says how many more there are — a wall of eighty names read out one at a
// time helps nobody deciding whether to adopt a folder.
define('STAXX_STORE_INSPECT_CAP', 25);

/**
 * Has Unraid's array actually finished starting?
 *
 * Read from Unraid's own state file rather than guessed from a folder's
 * presence, because a pool that is not yet mounted looks identical to one
 * that does not exist — and the whole reason this function exists is to tell
 * those two apart before inviting somebody to choose a location.
 *
 * mdState, not fsState: mdState is the array's own drive state and is
 * "STARTED" the moment every disk is up, which is what makes a pool's real
 * folders visible under /mnt. fsState covers the user-share filesystem layer
 * on top of that, which is a slower and separate thing to wait for, and
 * nothing here needs it — every offered location is read straight off the
 * pool, never through the share layer.
 *
 * Fails SAFE: if the file cannot be read at all, this reports NOT started.
 * The whole point of the check is to keep the first-run dialog from showing
 * only the flash drive while the good locations are still invisible, so an
 * unreadable file must never be read as "go ahead".
 */
function staxx_array_started(): bool {
  $ini = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
  if ($ini === false) return false;
  return trim((string)($ini['mdState'] ?? ''), '"') === 'STARTED';
}

/**
 * The plain-text note written into a new store's config folder as
 * README.txt. Adrian's own stated problem is not having to search the
 * heavens for where StaXX keeps things, so this is written for somebody
 * standing in the folder wondering what they are looking at — not for a
 * developer.
 */
function staxx_store_note(): string {
  return <<<TXT
This folder is StaXX's data store — everything the StaXX plugin keeps is in
here, in one place, rather than scattered across the flash drive.

stacks/     Every stack's compose file lives in its own folder here. This is
            the real, working configuration — the same file "docker compose"
            would read anywhere else.

archives/   A zip of each stack that has been removed, kept so it can be put
            back later.

config/     StaXX's own settings and housekeeping — the icon cache, update
            history and similar. This note lives here too.

Two small files stay on the flash drive and cannot move here:

  /boot/config/plugins/staxx/header_menu
  /boot/config/plugins/staxx/takeover_docker_tab

They only ever mark a setting as on or off — nothing is stored inside them —
but Unraid checks for their presence to decide where StaXX's menu appears,
and that check happens before the array has started. If they pointed in here
instead, StaXX's whole menu would vanish every time the server boots, because
this folder is not there yet at the moment the check runs.
TXT;
}

/**
 * What is already sitting in a folder somebody has typed or browsed to,
 * read-only — never creates, moves or writes anything.
 *
 * 'staxx' covers two shapes because both were built by StaXX at different
 * times: today's store keeps a stacks folder beside an archives folder, but
 * a folder used directly as a stack root (no store folder above it) can
 * still hold stacks with their own hidden record — and nothing but StaXX's
 * own writing ever puts one there. Either is enough to call it StaXX's own.
 *
 * @return array{state: string, stacks: string[], detail: string}
 */
function staxx_store_inspect(string $path): array {
  $norm = rtrim(trim($path), '/');
  if ($norm === '' || !is_dir($norm)) {
    return ['state' => 'missing', 'stacks' => [], 'detail' => 'This folder does not exist yet — it will be created.'];
  }

  $entries = array_values(array_diff((array)@scandir($norm), ['.', '..']));
  if ($entries === []) {
    return ['state' => 'empty', 'stacks' => [], 'detail' => 'This folder exists and is empty.'];
  }

  // The store shape: a stacks folder sitting beside an archives folder.
  // Nothing else makes that pair, so its presence alone is enough.
  $stacksDir = is_dir($norm.'/stacks') ? $norm.'/stacks' : null;
  $hasStore  = $stacksDir !== null && is_dir($norm.'/archives');

  // The older, storeless shape: stacks sitting directly in this folder, with
  // at least one carrying its own hidden record. Checked only when the store
  // shape above did not already match, since a store's own stacks/ folder
  // would otherwise be mistaken for a stack itself.
  $scanDir = $hasStore ? $stacksDir : $norm;
  $found   = [];
  $ownedByStaxx = $hasStore;

  /* Counted the same way the stack model itself decides: a directory holding
   * a compose file IS a stack, and a directory that holds none but contains
   * directories that do is a FOLDER, whose children are stacks named
   * "Media/jellyfin". One level down and no further, exactly as everywhere
   * else — a stack cannot contain another stack.
   *
   * Looking only at the top level, as this first did, reported a real store
   * of eighty compose files as holding four, because everything filed into a
   * folder was invisible to it. Undercounting here is worse than it sounds:
   * this is the screen that tells somebody whether the folder they are about
   * to adopt is the one with their stacks in it. */
  $isStack = function (string $dir): bool {
    foreach (STAXX_COMPOSE_FILENAMES as $name) {
      if (is_file($dir.'/'.$name)) return true;
    }
    return false;
  };
  $note = function (string $dir) use (&$ownedByStaxx): void {
    if (is_dir($dir.'/'.STAXX_RECORD_DIR)) $ownedByStaxx = true;
  };

  foreach ((array)@scandir($scanDir) as $entry) {
    if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
    $dir = $scanDir.'/'.$entry;
    if (!is_dir($dir)) continue;

    if ($isStack($dir)) {
      $found[] = $entry;
      $note($dir);
      continue;
    }

    foreach ((array)@scandir($dir) as $child) {
      if ($child === '.' || $child === '..' || $child[0] === '.') continue;
      $sub = $dir.'/'.$child;
      if (!is_dir($sub) || !$isStack($sub)) continue;
      $found[] = $entry.'/'.$child;
      $note($sub);
    }
  }

  if ($found !== []) {
    $count = count($found);
    $shown = array_slice($found, 0, STAXX_STORE_INSPECT_CAP);
    $more  = $count - count($shown);
    /* The count is already the whole total, so this qualifies the LIST below
     * it, never the number — written the other way round it read as "84
     * stacks (and 59 more)", which looks like a total of 143. */
    $suffix = $more > 0 ? ' The first '.count($shown).' are listed here.' : '';

    if ($ownedByStaxx) {
      $detail = 'This is already a StaXX store, holding '.$count.' stack'.($count === 1 ? '' : 's').'.'.$suffix;
      return ['state' => 'staxx', 'stacks' => $shown, 'detail' => $detail];
    }

    $detail = 'This folder holds '.$count.' compose file'.($count === 1 ? '' : 's').', but none '
            . 'were written by StaXX — nothing has been described or given an icon yet. They will run '
            . 'exactly as they are.'.$suffix;
    return ['state' => 'compose', 'stacks' => $shown, 'detail' => $detail];
  }

  // A store with nothing in it yet is still ours, and this is the shape
  // staxx_store_create() itself leaves behind — so reopening the dialog on a
  // store chosen a moment ago must recognise it rather than call it somebody
  // else's folder. The stacks/archives pair is what identifies it; having no
  // stacks in it yet says nothing about whose it is.
  if ($hasStore) {
    return ['state' => 'staxx', 'stacks' => [],
            'detail' => 'This is already a StaXX store, with no stacks in it yet.'];
  }

  // Something is here, but not stacks in either shape — an unrelated folder,
  // or a store folder holding only one of stacks/archives.
  return ['state' => 'other', 'stacks' => [],
          'detail' => 'This folder already holds something, but it does not look like a StaXX store '
                     . 'or a folder of compose files.'];
}

/**
 * Create a data store at $path and record the choice. Safe to run against a
 * folder that is already a StaXX store: every directory is created with
 * is_dir()-guarded mkdir(), so adopting one disturbs nothing already inside
 * it, and the note is rewritten only when its text has actually changed, so
 * an existing README.txt is never touched for no reason.
 *
 * Validation is not repeated here — staxx_settings_validate_path() is the one
 * place those rules live, and staxx_settings_save() below runs it again on
 * its own account, which is the same function, not a second copy of it.
 */
function staxx_store_create(string $path, string &$error): bool {
  $error = '';

  $norm = staxx_settings_validate_path('STORE_ROOT', $path, $error);
  if ($norm === '') return false; // $error already set

  foreach ([$norm, $norm.'/stacks', $norm.'/archives', $norm.'/config'] as $dir) {
    if (is_dir($dir)) continue;
    if (!@mkdir($dir, 0755, true)) {
      $error = 'Could not create "'.$dir.'" — check that StaXX can write to that location.';
      return false;
    }
  }

  $notePath = $norm.'/config/README.txt';
  $note     = staxx_store_note();
  $existing = is_file($notePath) ? @file_get_contents($notePath) : false;
  if ($existing !== $note) {
    if (@file_put_contents($notePath, $note) === false) {
      $error = 'Could not write "'.$notePath.'" — check that StaXX can write to that location.';
      return false;
    }
  }

  if (!staxx_settings_save(['STORE_ROOT' => $norm], $error)) {
    return false; // $error already set by staxx_settings_save()
  }

  return true;
}
?>
