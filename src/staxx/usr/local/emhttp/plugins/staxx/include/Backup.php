<?PHP
/* StaXX — is the store actually in somebody's backup?
 * Copyright 2026, StaXX contributors.
 *
 * The compose files are the definition of the whole Docker setup, and nothing
 * backs them up by default. The Appdata Backup plugin — the tool most people
 * on Unraid already run — works from each CONTAINER's volume mappings, and a
 * plugin's own store is not a container volume, so it is never included. The
 * proof is on any box that has both: Compose Manager's project folder sits in
 * appdata beside StaXX's and appears in none of the archives. Being inside
 * appdata is not being backed up; somebody has to name the folder.
 *
 * So this file answers exactly one question — are StaXX's folders named in
 * that plugin's "include extra files/folders" list — and it does so READ-ONLY.
 * It never writes to that config. That file belongs to another plugin, their
 * settings page rewrites it wholesale on save, and the thing a bad write
 * would break is the backup this exists to protect.
 *
 * Two rules make it safe to depend on another plugin's private format:
 *
 *  1. Only ever assert the NEGATIVE. Missing file, unparseable JSON, renamed
 *     key, unexpected type — every one of those returns null, meaning "say
 *     nothing". A format change on their side switches this feature off; it
 *     can never invent an alarm.
 *  2. Never claim "backed up". Only ever "listed" or "not listed". Whether a
 *     run succeeds depends on their whole pipeline and an off-box destination
 *     that may not even be reachable. That a string is absent from a list
 *     just read is the only claim safe to make.
 *
 * There is deliberately no version check on their plugin: a version test rots
 * faster than a format does, and would turn a working read into a refusal.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
/* Stacks.php, not just Defines.php: staxx_stack_root() lives there, and
 * staxx_backup_owned_paths() is built from it. Nothing here is reached from
 * Stacks.php in return except through function_exists(), so there is no
 * loading order to get wrong. */
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_BACKUP_LOADED')) return;
define('STAXX_BACKUP_LOADED', true);

/* All three live on the flash drive, so an answer built from them survives a
 * reboot — unlike anything under /usr/local/emhttp, which is rebuilt at boot.
 * The legacy CA name is checked too: it is the plugin this one replaced, and
 * an install that never migrated still has its marker and no new one. */
define('STAXX_AB_CONFIG',   '/boot/config/plugins/appdata.backup/config.json');
define('STAXX_AB_MARKERS',  '/boot/config/plugins/appdata.backup.plg,/boot/config/plugins/ca.backup2.plg');

// Where to send someone to fix it. Relative on purpose — a server is reached
// by hostname, by IP and by the remote-access domain, and a link naming one of
// those breaks on the other two.
define('STAXX_AB_SETTINGS_URL', '/Settings/AB.Main#includeFiles');

/**
 * Is the Appdata Backup plugin installed at all? Nothing else here is worth
 * asking otherwise, and someone who has chosen not to use it should never be
 * nagged about it.
 */
function staxx_backup_plugin_installed(): bool {
  foreach (explode(',', STAXX_AB_MARKERS) as $marker) {
    if (is_file($marker)) return true;
  }
  return false;
}

/** Trailing slashes and stray carriage returns off; '' if nothing is left. */
function staxx_backup_norm(string $p): string {
  $p = rtrim(trim(str_replace("\r", '', $p)), '/');
  return $p === '' ? '' : $p;
}

/**
 * The folders StaXX owns and that therefore need backing up.
 *
 * Asked for rather than hard-coded anywhere else, because separate work
 * collapses the two into a single store. When the archive folder ends up
 * INSIDE the stacks folder this returns one path instead of two, on its own,
 * and every caller carries on unchanged.
 *
 * It will not invent a shared parent, though, however tempting: today the two
 * are siblings under somebody's appdata share, and the only path containing
 * both is appdata itself. Naming that would tell someone to back up every
 * container's data to cover StaXX's, which is a far bigger claim than this
 * file is entitled to make.
 */
function staxx_backup_owned_paths(): array {
  $paths = [];
  foreach ([staxx_stack_root(), staxx_archive_root()] as $p) {
    $p = staxx_backup_norm($p);
    if ($p !== '') $paths[$p] = true;
  }
  $paths = array_keys($paths);

  // Drop anything sitting inside another of them — one entry already covers it.
  $out = [];
  foreach ($paths as $p) {
    $inside = false;
    foreach ($paths as $other) {
      if ($other !== $p && strpos($p, $other.'/') === 0) { $inside = true; break; }
    }
    if (!$inside) $out[] = $p;
  }
  sort($out);
  return $out;
}

/**
 * Does one listed entry cover this path — is it the path itself, or a folder
 * the path sits inside?
 *
 * Generous about the one difference that is not a real one: /mnt/user/appdata
 * and /mnt/<pool>/appdata are the same directory when that share lives on that
 * pool, so an entry written either way counts. Both sides have to differ in
 * exactly that respect for the tails to be compared — two different pools
 * sharing a tail are genuinely different places, and treating them as one
 * would be the silence that hides a real gap.
 */
function staxx_backup_covers_one(string $entry, string $path): bool {
  if ($entry === '' || $path === '') return false;
  if ($entry === $path || strpos($path, $entry.'/') === 0) return true;

  if (!preg_match('#^/mnt/([^/]+)/(.+)$#', $entry, $me)) return false;
  if (!preg_match('#^/mnt/([^/]+)/(.+)$#', $path,  $mp)) return false;
  if (($me[1] === 'user') === ($mp[1] === 'user')) return false;

  return $me[2] === $mp[2] || strpos($mp[2], $me[2].'/') === 0;
}

/**
 * The extras list from the backup plugin's config, normalised — or null when
 * no claim can be made about it. Null is the answer for a file that will not
 * read or parse, a key that is absent, and a key holding anything other than
 * a list of strings. See rule 1 in the file header: every one of those has to
 * mean "say nothing", never "not listed".
 *
 * No install check here on purpose. A missing config file is the same answer
 * as a missing plugin — say nothing — so testing both would be testing one
 * thing twice. staxx_backup_plugin_installed() exists for the separate
 * question of whether to offer a fix-it button at all.
 *
 * $configPath is a parameter so the test suite can hand it a fixture. Nothing
 * in the plugin passes it.
 */
function staxx_backup_entries(string $configPath = STAXX_AB_CONFIG): ?array {
  if (!is_file($configPath) || !is_readable($configPath)) return null;

  $raw = @file_get_contents($configPath);
  if ($raw === false || $raw === '') return null;

  $cfg = @json_decode($raw, true);
  if (!is_array($cfg) || !array_key_exists('includeFiles', $cfg)) return null;

  $list = $cfg['includeFiles'];
  // Their settings page stores this as an array, but it is a textarea behind
  // the scenes, so a single string of CRLF-separated lines is the other shape
  // worth accepting rather than reading as "nothing listed".
  if (is_string($list)) $list = preg_split('/\r\n|\r|\n/', $list);
  if (!is_array($list)) return null;

  $out = [];
  foreach ($list as $item) {
    if (!is_string($item)) return null;   // an unexpected shape: say nothing at all
    $item = staxx_backup_norm($item);
    if ($item !== '') $out[] = $item;
  }
  return $out;
}

/**
 * Which of StaXX's folders are named in that list, and which are missing.
 * Null when nothing can be said (see staxx_backup_entries()). An empty
 * 'missing' means every owned path is listed — which is still not a promise
 * that a backup will run, only that the folders are named.
 *
 * @return array{listed:string[], missing:string[], url:string}|null
 */
function staxx_backup_coverage(string $configPath = STAXX_AB_CONFIG): ?array {
  $entries = staxx_backup_entries($configPath);
  if ($entries === null) return null;

  $listed = $missing = [];
  foreach (staxx_backup_owned_paths() as $path) {
    $found = false;
    foreach ($entries as $entry) {
      if (staxx_backup_covers_one($entry, $path)) { $found = true; break; }
    }
    if ($found) $listed[] = $path; else $missing[] = $path;
  }

  return ['listed' => $listed, 'missing' => $missing, 'url' => STAXX_AB_SETTINGS_URL];
}

/**
 * Is this one path — an OLD stacks folder, after a move — still named in the
 * list? A move leaves the old entry behind pointing at a folder that no
 * longer exists, and the backup then keeps running, keeps reporting success,
 * and copies nothing. That silent version of a working backup is the worst
 * failure in this whole area, which is why it gets its own question.
 *
 * Null carries the same meaning as everywhere else here: no claim available.
 */
function staxx_backup_lists_path(string $path, string $configPath = STAXX_AB_CONFIG): ?bool {
  $entries = staxx_backup_entries($configPath);
  if ($entries === null) return null;

  $path = staxx_backup_norm($path);
  if ($path === '') return null;

  foreach ($entries as $entry) {
    if (staxx_backup_covers_one($entry, $path)) return true;
  }
  return false;
}
?>
