<?PHP
/* StaXX — a shelf copy of every compose file, on the boot drive.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_103. The data store holds the real stacks; this is a second, plain
 * copy of each stack's compose file (with its override and its .env file,
 * if it has them) kept at /boot/staxx so a person who loses the store — a dead pool, nothing
 * else — has not lost the definition of every container they run. Unraid
 * backs up the whole flash drive on its own, so this copy leaves the
 * machine without anyone arranging it.
 *
 * FOUR RULES KEEP THIS FROM BECOMING A SECOND TRUTH, and they are refusals
 * in the code, not comments claiming them:
 *
 *   1. Nothing here ever reads the copy back for StaXX's own use. There is
 *      no function in this file that opens a file under staxx_boot_copy_root()
 *      to answer a question the store could instead — read the file, that is
 *      the whole list of what this offers.
 *   2. Nothing here compares a copy's timestamp with the store's. The only
 *      timestamp involved is the filesystem's own mtime on the copy, and it
 *      is never read here — see the note on staxx_boot_write_file() below.
 *   3. A copy that could not be written never fails the save, rename, or
 *      removal that triggered it — every entry point below returns cleanly
 *      and leaves it to the caller to log a note, exactly as a history that
 *      could not be kept already does in staxx_save_stack().
 *   4. Nothing here ever writes back into the store. Restoring from the
 *      shelf is a person's decision, made elsewhere (PLAN_103 pass 2); this
 *      file only ever writes to, or removes from, /boot/staxx.
 *
 * History is deliberately not copied — only the compose file, its override
 * and its .env, mirroring the store's own shape. That stays beside the compose
 * file where it belongs; see PLAN_103.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_BOOTCOPY_LOADED')) return;
define('STAXX_BOOTCOPY_LOADED', true);

// Fixed at /boot/staxx for real use — PLAN_103 is deliberately one place,
// always the same place; a configurable second location would be a second
// store by another name, so this is NOT a setting. tests/server/bootcopy.php
// still has to prove this against a real flash drive without touching the
// real shelf, so it points this at a scratch folder under /boot the same
// way tests/server/autostart.php redirects STAXX_AUTOSTART_FILE — an
// environment variable a person would have to go out of their way to set,
// not a config key anyone could flip by accident.
$staxx_boot_copy_env = getenv('STAXX_BOOT_COPY_ROOT');
define('STAXX_BOOT_COPY_ROOT', ($staxx_boot_copy_env !== false && $staxx_boot_copy_env !== '')
  ? $staxx_boot_copy_env
  : '/boot/staxx');

/** The top of the shelf. See the STAXX_BOOT_COPY_ROOT comment above for why
 *  this is an environment override, not a setting. */
function staxx_boot_copy_root(): string {
  return STAXX_BOOT_COPY_ROOT;
}

/** Where the copies themselves live, mirroring <store>/stacks. */
function staxx_boot_stacks_root(): string {
  return staxx_boot_copy_root().'/stacks';
}

/** The setting gate. Ships on — see default.cfg for why. */
function staxx_boot_copy_enabled(): bool {
  return staxx_cfg_bool('BOOT_COPY');
}

/** The plain-English note left at the top of the shelf, same approach as
 *  staxx_store_note(): a short explanation, rewritten only when it changes. */
function staxx_boot_note(): string {
  return <<<TXT
These are copies of your stacks' compose files, kept here in case the real
copies — on your data store — are ever lost.

They are backup only. StaXX never reads them while the store is present, and
they can be older than what is actually running: a copy is only as fresh as
the last save that managed to reach this drive. If a compose file looks
wrong, trust the data store, not this folder.

Bringing a stack back from here is a StaXX plugin feature — open StaXX and
look for the offer to restore, rather than copying files by hand.
TXT;
}

/**
 * Create the shelf's folders and (re)write its note, only when the text has
 * actually changed — the same pattern staxx_store_create() uses for the data
 * store's own README.
 *
 * No mode is set on anything created here. Everything under /boot is
 * owner-only whatever chmod asks for, because that is how the flash drive
 * is mounted — the mode argument below is passed only because mkdir()
 * requires one, and is moot the moment it lands on this filesystem.
 */
function staxx_boot_ensure_shelf(string &$error): bool {
  $error = '';
  foreach ([staxx_boot_copy_root(), staxx_boot_stacks_root()] as $dir) {
    if (is_dir($dir)) continue;
    if (!@mkdir($dir, 0755, true)) {
      $error = 'Could not create "'.$dir.'" on the boot drive.';
      return false;
    }
  }

  $notePath = staxx_boot_copy_root().'/README.txt';
  $note     = staxx_boot_note();
  $existing = is_file($notePath) ? @file_get_contents($notePath) : false;
  if ($existing !== $note && @file_put_contents($notePath, $note) === false) {
    $error = 'Could not write "'.$notePath.'" on the boot drive.';
    return false;
  }
  return true;
}

/**
 * Does $name already sit in $parentDir under a different capitalisation?
 * The flash drive folds case, so "Media" and "media" are one name there —
 * folders and stacks share a single namespace in the store, so two that
 * differ only by case coexist happily there and collide here. Checked with
 * scandir() against the real directory rather than by comparing strings
 * StaXX already holds, the same reasoning tests/server/relocate.php's own
 * trial run uses: what matters is what is actually on this filesystem, not
 * what two names look like typed out.
 */
function staxx_boot_case_clash(string $parentDir, string $name, string &$existing): bool {
  if (!is_dir($parentDir)) return false;
  foreach ((array)@scandir($parentDir) as $entry) {
    if ($entry === '.' || $entry === '..' || $entry === $name) continue;
    if (strcasecmp($entry, $name) === 0) { $existing = $entry; return true; }
  }
  return false;
}

/**
 * The bytes to copy for one file, following a symlink to what it points at
 * rather than skipping it or storing the link itself — the flash drive
 * cannot hold a symlink at all. A link that cannot be resolved is reported,
 * never silently dropped.
 */
function staxx_boot_read_source(string $path, string &$error): ?string {
  $real = is_link($path) ? @realpath($path) : $path;
  if ($real === false || !is_file($real)) {
    $error = '"'.basename($path).'" is a link to somewhere that could not be read, '
           . 'so it was not copied.';
    return null;
  }
  $content = @file_get_contents($real);
  if ($content === false) {
    $error = 'Could not read "'.basename($path).'" to copy it.';
    return null;
  }
  return $content;
}

/**
 * Write one file into place on the shelf, temp-then-rename so a reader never
 * sees half a file. This is also the one place a per-stack "when" exists: no
 * separate stamp file is written, because the copy's own filesystem
 * modification time already records when it landed here, one per file, for
 * free — inventing a second place to store the same fact would be a second
 * thing that could disagree with the first.
 */
function staxx_boot_write_file(string $target, string $content, string &$error): bool {
  $tmp = $target.'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $content) === false) {
    $error = 'Could not write "'.$target.'" on the boot drive.';
    return false;
  }
  if (!@rename($tmp, $target)) {
    @unlink($tmp);
    $error = 'Could not put "'.$target.'" in place on the boot drive.';
    return false;
  }
  return true;
}

/**
 * Copy one stack's compose file, with its override and .env if it has them,
 * to the shelf. A no-op returning true when the setting is off — every call site
 * below simply calls this after its own work has already succeeded, so a
 * caller never needs its own "if enabled" check.
 *
 * Refuses (leaving whatever the shelf already held untouched) rather than
 * overwriting on a case clash, and reads through a compose file that is
 * itself a symlink rather than skipping it — see the two trap comments
 * above. Never fails the caller: every entry point below only logs $error,
 * it does not act on it.
 */
function staxx_boot_copy_stack(string $rel, string &$error): bool {
  $error = '';
  if (!staxx_boot_copy_enabled()) return true;
  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return false; }

  $srcDir = staxx_stack_dir($rel);
  $main   = staxx_find_compose_file($srcDir);
  if ($main === '') { $error = 'No compose file found for "'.$rel.'" to copy.'; return false; }

  if (!staxx_boot_ensure_shelf($error)) return false;

  // Build the destination one path segment at a time — "folder/leaf" or
  // just "leaf" — checking each for a case clash before it is created, the
  // same order staxx_relocate_trial() checks a name before placing it.
  $dir = staxx_boot_stacks_root();
  foreach (explode('/', $rel) as $part) {
    $clash = '';
    if (staxx_boot_case_clash($dir, $part, $clash)) {
      $error = 'The boot copy of "'.$rel.'" was not written: "'.$part.'" collides with "'
             . $clash.'" already on the boot drive, which does not tell the two names apart. '
             . 'Neither was touched.';
      return false;
    }
    $dir .= '/'.$part;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { // mode moot on /boot, see staxx_boot_ensure_shelf()
      $error = 'Could not create "'.$dir.'" on the boot drive.';
      return false;
    }
  }

  $wanted = [];
  foreach (staxx_compose_files($main) as $src) {
    $content = staxx_boot_read_source($src, $error);
    if ($content === null) return false;
    $target = $dir.'/'.basename($src);
    if (!staxx_boot_write_file($target, $content, $error)) return false;
    $wanted[] = basename($target);
  }

  // A stack that had an override at its last copy and does not now must not
  // leave that override sitting on the shelf looking current.
  $overrideName = staxx_expected_override_basename($main);
  if ($overrideName !== '' && !in_array($overrideName, $wanted, true)) {
    @unlink($dir.'/'.$overrideName);
  }

  // The .env file too, since a compose file full of ${PLACEHOLDERS} is not
  // a definition of anything without it — a restore from the shelf alone
  // would give a stack that cannot start. Overwritten on every copy, and
  // removed from the shelf when the stack no longer has one, for the same
  // reason as the override above. Adrian's call, 2026-09-03 (PLAN_129 item
  // 27): the compose file itself may already carry secrets in plain text, so
  // this is no new exposure, and an incomplete copy is the worse failure.
  $envSrc = $srcDir.'/.env';
  if (is_file($envSrc)) {
    $content = staxx_boot_read_source($envSrc, $error);
    if ($content === null) return false;
    if (!staxx_boot_write_file($dir.'/.env', $content, $error)) return false;
  } else {
    @unlink($dir.'/.env');
  }

  return true;
}

/**
 * Remove a stack's copy from the shelf, because a shelf that quietly keeps
 * what somebody deleted is a resurrection waiting to happen. Not gated on
 * the setting: this only ever deletes, and a copy that was never made is
 * simply not there to remove.
 */
function staxx_boot_remove_stack(string $rel): void {
  if (!staxx_valid_path($rel)) return;
  $dir = staxx_boot_stacks_root().'/'.$rel;
  if (!is_dir($dir)) return;
  staxx_rmtree($dir, $dir);
}
?>
