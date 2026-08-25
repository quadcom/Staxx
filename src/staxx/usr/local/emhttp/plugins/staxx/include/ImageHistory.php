<?PHP
/* StaXX — a stack's own record of the images it has run.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_82 Part 1: the fingerprint-before-every-update list that
 * staxx_update_history_push() has always kept lived in one central file for
 * the whole server, keyed by "stack::service". That has the same two faults
 * Record.php's edit history was built to avoid — it does not follow a rename
 * or a move, and entries for a deleted stack accumulate for ever. This file
 * is the new home for that data, kept inside the same per-stack record
 * (under its own "images" key, beside "versions"), plus the migration that
 * moves entries there out of the central file, lazily, one stack at a time.
 *
 * The same rule as Record.php governs everything here: NOTHING IN THE PLUGIN
 * MAY EVER REQUIRE THIS TO EXIST. A missing, truncated or hand-mangled
 * record means "no image history", never a warning or a fatal — the stack
 * still lists, edits, starts, stops and rolls back on whatever the central
 * file still holds. Only staxx_image_history_push() and
 * staxx_image_history_adopt()/staxx_image_history_sweep() ever write; every
 * other function here is a plain best-effort read.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
// staxx_update_settings()['retain'] (UpdateRun.php) and staxx_update_state()/
// staxx_update_state_save() (Updates.php, required by UpdateRun.php) are the
// central file this migrates out of — read here, never written to except by
// the adopt/sweep migration below.
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

if (defined('STAXX_IMAGEHISTORY_LOADED')) return;
define('STAXX_IMAGEHISTORY_LOADED', true);

/**
 * Is this a decoded image-history entry shaped exactly the way one must be?
 * Same reasoning as staxx_record_valid_entry() in Record.php: every field's
 * type is checked, not just its presence, since this file is as hand-
 * editable as the rest of a stack's record.
 */
function staxx_image_history_valid_entry($entry): bool {
  if (!is_array($entry)) return false;
  if (!array_key_exists('digest', $entry) || !array_key_exists('at', $entry)
    || !array_key_exists('version', $entry) || !array_key_exists('source', $entry)) {
    return false;
  }
  // "algo:hex" — the shape docker itself prints, and the only shape
  // staxx_update_history_push() has ever recorded.
  if (!is_string($entry['digest']) || !preg_match('/^[a-z0-9+._-]+:[0-9a-f]{32,}$/', $entry['digest'])) {
    return false;
  }
  if (!is_int($entry['at']) || $entry['at'] < 0) return false;
  if (!is_string($entry['version'])) return false;
  if (!is_string($entry['source'])) return false;
  return true;
}

/**
 * Is this a decoded "images" map shaped the way one must be — a plain list
 * of valid entries under every service key? One bad entry invalidates the
 * whole map rather than being silently dropped, same as Record.php's own
 * versions list, and for the same reason: a partially-trusted map is how a
 * caller ends up reading history that was never really there.
 *
 * Deliberately independent of staxx_record_valid_entry(): a malformed
 * "images" key must never take the compose-edit history down with it, and a
 * malformed "versions" list must never take this down with it either — see
 * staxx_record_read() in Record.php, which validates the two halves
 * separately for exactly that reason.
 */
function staxx_image_history_valid_map($images): bool {
  if (!is_array($images)) return false;
  foreach ($images as $service => $list) {
    if (!is_string($service) || $service === '') return false;
    if (!is_array($list)) return false;
    if (array_keys($list) !== range(0, count($list) - 1)) return false;
    foreach ($list as $entry) {
      if (!staxx_image_history_valid_entry($entry)) return false;
    }
  }
  return true;
}

/**
 * Prepend one fingerprint to a service's image history, in its stack's own
 * record. Never the same digest twice running — a repeatedly recreated
 * container would otherwise fill the list with copies of itself — and
 * capped at the same retention setting the central file always honoured, so
 * moving house does not change how much is kept.
 *
 * Best-effort throughout, like every write in Record.php: a stack that
 * cannot be written to (read-only media, a missing stack directory) simply
 * keeps no image history, silently. There is no $error out param because
 * nothing calling this — an update pass — has anywhere to show one.
 */
function staxx_image_history_push(string $stack, string $service, string $digest, array $meta): void {
  if ($digest === '' || $service === '') return;
  if (!staxx_valid_path($stack)) return;

  $dir = staxx_record_dir($stack);
  if (!@is_dir($dir) && !@mkdir($dir, 0755, true)) return;

  $record = staxx_record_read($stack);
  $images = $record['images'] ?? [];
  $list   = $images[$service] ?? [];

  if (($list[0]['digest'] ?? '') === $digest) return;

  $entry = [
    'digest'  => $digest,
    'at'      => time(),
    'version' => (string)($meta['version'] ?? ''),
    'source'  => (string)($meta['source'] ?? ''),
  ];

  // Written only if it would be read back. The reader validates the digest's
  // shape, so a writer that did not apply the same test could store an entry
  // that reads as an empty history for ever after — a silent write of
  // unreadable data, which is the worst of both answers. Checked with the
  // reader's own function so the two can never drift apart.
  if (!staxx_image_history_valid_entry($entry)) {
    error_log('StaXX: refusing to record an image version for '.$stack.'/'.$service
            . ' — "'.$digest.'" is not a digest this can read back.');
    return;
  }

  array_unshift($list, $entry);

  $retain = staxx_update_settings()['retain'];
  $images[$service] = array_slice($list, 0, max(0, $retain));

  staxx_record_write_index($stack, [
    'v'        => 1,
    'next'     => $record['next'] ?? 1,
    'versions' => $record['versions'] ?? [],
    'images'   => $images,
  ]);
}

/** Full entries for one service, newest first. [] when there are none. */
function staxx_image_history(string $stack, string $service): array {
  if (!staxx_valid_path($stack) || $service === '') return [];
  $images = staxx_record_read($stack)['images'] ?? [];
  return $images[$service] ?? [];
}

/**
 * Just the digest strings, newest first — the shape the rollback and the
 * image cleanup have always wanted, so neither has to change to read from
 * here instead of the central file.
 */
function staxx_image_history_digests(string $stack, string $service): array {
  return array_column(staxx_image_history($stack, $service), 'digest');
}

/**
 * Every stack-and-service that has recorded image history, in the exact
 * '<stack>::<service>' => [digests...] shape the central file has always
 * used, so the image cleanup can be pointed at this with the least possible
 * change.
 *
 * SAFETY-CRITICAL: the cleanup calls this to decide which images are still
 * wanted for a rollback before it deletes anything. This must walk every
 * stack that actually exists, not a cached or partial list — a digest
 * missing from this answer is an image that gets removed while a rollback
 * still needs it.
 */
function staxx_image_history_all(): array {
  $all = [];
  foreach (staxx_list_stacks() as $s) {
    $images = staxx_record_read($s['name'])['images'] ?? [];
    foreach ($images as $service => $list) {
      $digests = array_column($list, 'digest');
      if ($digests) $all[$s['name'].'::'.$service] = $digests;
    }
  }
  return $all;
}

/**
 * Move one stack's entries out of the central file and into its own record
 * — lazily, the first time anything asks about this stack, rather than a
 * one-shot global pass. That makes it naturally idempotent and needs no
 * "have we migrated yet" flag anywhere: nothing left to move is not a
 * failure, it is the normal answer once a stack has already been migrated.
 *
 * The record's own entries always win, because the central file is the
 * older copy — a migrated digest already present is skipped, and whatever
 * is left from the central file settles in behind the record's own newer
 * entries rather than in front of them. Only this stack's own keys are ever
 * touched in the central file; everything else it holds is left alone.
 */
function staxx_image_history_adopt(string $stack, string &$error): bool {
  $error = '';
  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return false; }

  $state   = staxx_update_state();
  $history = (array)($state['history'] ?? []);
  $prefix  = $stack.'::';

  $toMigrate = [];
  foreach ($history as $key => $digests) {
    if (strncmp((string)$key, $prefix, strlen($prefix)) === 0) $toMigrate[$key] = $digests;
  }
  if (!$toMigrate) return true; // nothing to move for this stack — not a failure

  $dir = staxx_record_dir($stack);
  if (!@is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $error = 'Could not create this stack\'s record, so its image history was left in the central file.';
    return false;
  }

  $record = staxx_record_read($stack);
  $images = $record['images'] ?? [];

  foreach ($toMigrate as $key => $digests) {
    $service = substr((string)$key, strlen($prefix));
    if ($service === '') continue;

    $existing = $images[$service] ?? [];
    $known    = array_column($existing, 'digest');

    // The central list is already newest first; append whatever is not
    // already known, in that same order, so it settles in behind the
    // record's own (newer) entries as the older history it is. There is no
    // real timestamp to carry across — the central file only ever kept the
    // digest — so 'at' is recorded as 0 rather than invented.
    foreach ((array)$digests as $digest) {
      if (!is_string($digest) || $digest === '') continue;
      if (in_array($digest, $known, true)) continue;
      $existing[] = ['digest' => $digest, 'at' => 0, 'version' => '', 'source' => ''];
      $known[] = $digest;
    }

    $retain = staxx_update_settings()['retain'];
    $images[$service] = array_slice($existing, 0, max(0, $retain));
  }

  $ok = staxx_record_write_index($stack, [
    'v'        => 1,
    'next'     => $record['next'] ?? 1,
    'versions' => $record['versions'] ?? [],
    'images'   => $images,
  ]);
  if (!$ok) {
    $error = 'Could not write this stack\'s record, so its image history was left in the central file.';
    return false;
  }

  // Only clear the central file's copy once the record write above is
  // confirmed — left exactly as it was on any failure, so a write that
  // fails halfway never loses an entry from either side.
  foreach (array_keys($toMigrate) as $key) unset($history[$key]);
  if (!staxx_update_state_save(['history' => $history])) {
    $error = 'Moved this stack\'s image history into its own record, but could not clear it from the central file afterwards.';
    return false;
  }

  return true;
}

/**
 * The sweep for orphans the lazy adopt can never find on its own: entries
 * left in the central file for a stack that no longer exists at all — one
 * that was deleted, or renamed before this ever ran. Walks every key the
 * central file still holds, adopts what maps to a stack that still exists,
 * and drops the rest.
 *
 * The dropped count is handed back rather than swallowed — deleting stale
 * entries silently would hide the very problem the per-stack record exists
 * to fix. $moved counts central-file entries (one per "stack::service" key)
 * successfully migrated; $dropped counts those removed because no such
 * stack exists any more.
 */
function staxx_image_history_sweep(int &$moved, int &$dropped, string &$error): bool {
  $moved = 0; $dropped = 0; $error = '';

  $state   = staxx_update_state();
  $history = (array)($state['history'] ?? []);
  if (!$history) return true;

  // Existence is asked of the disk, not of staxx_list_stacks(), whose answer
  // is memoised for the rest of the request. A stale list here would call a
  // stack that does exist an orphan and delete its history — deciding to
  // destroy something on the strength of a cached answer is the wrong bet
  // whichever way the cache happens to be facing.
  $exists = function (string $stack): bool {
    return $stack !== '' && staxx_valid_path($stack)
        && staxx_find_compose_file(staxx_stack_dir($stack)) !== '';
  };

  // Grouped by stack first: staxx_image_history_adopt() moves every key for
  // one stack in a single record write, so it is called once per stack
  // rather than once per key.
  $byStack = [];
  $orphans = [];
  foreach (array_keys($history) as $key) {
    $sep   = strrpos((string)$key, '::');
    $stack = $sep === false ? '' : substr((string)$key, 0, $sep);
    if ($exists($stack)) $byStack[$stack][] = $key;
    else $orphans[] = $key;
  }

  foreach ($byStack as $stack => $keys) {
    $adoptError = '';
    // A write failure leaves those entries exactly where they were, to be
    // tried again on the next sweep — never counted as moved, and never
    // dropped either, since nothing here says they are stale.
    if (staxx_image_history_adopt($stack, $adoptError)) $moved += count($keys);
  }

  if ($orphans) {
    // Re-read: the adopt calls above may already have saved the central
    // file, and this must drop the orphans from what is on disk NOW, not
    // from the copy read before those saves.
    $fresh     = staxx_update_state();
    $remaining = (array)($fresh['history'] ?? []);
    foreach ($orphans as $key) unset($remaining[$key]);

    if (!staxx_update_state_save(['history' => $remaining])) {
      $error = 'Could not remove the stale entries from the central file.';
      return false;
    }
    $dropped = count($orphans);
  }

  return true;
}
?>
