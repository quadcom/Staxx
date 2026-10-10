<?PHP
/* StaXX — a stack's own record of the images it has run.
 * Copyright 2026, StaXX contributors.
 *
 * Each stack keeps its own record of the images it has run, under the
 * record's "images" key beside "versions". It lives per stack so that it
 * follows a rename or a move, and a deleted stack's entries go with it.
 *
 * The same rule as Record.php governs everything here: NOTHING IN THE PLUGIN
 * MAY EVER REQUIRE THIS TO EXIST. A missing, truncated or hand-mangled
 * record means "no image history", never a warning or a fatal. Only
 * staxx_image_history_push() ever writes; every other function here is a
 * plain best-effort read.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
// staxx_update_settings()['retain'] (UpdateRun.php) caps the list in the push.
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

  // Release notes: optional on every entry, since every one already on disk
  // predates them — absent is valid, present must be the right type.
  if (array_key_exists('notes', $entry) && !is_string($entry['notes'])) return false;
  if (array_key_exists('notesUrl', $entry) && !is_string($entry['notesUrl'])) return false;
  if (array_key_exists('notesCut', $entry) && !is_bool($entry['notesCut'])) return false;

  // What went into this build: the commit it was made from, and — when no
  // release could be named — the commit subjects between the previously
  // recorded build and this one. Optional on exactly the same terms as the
  // notes keys above, since every entry recorded before PLAN_82a has none of
  // them. 'changes' must be a plain list — asked with array_is_list() rather
  // than by comparing keys against range(), because range(0, -1) counts
  // DOWNWARDS and yields [0, -1], so the hand-rolled test rejects an empty
  // list. Empty is a perfectly well-formed list and must validate.
  if (array_key_exists('revision', $entry) && !is_string($entry['revision'])) return false;
  if (array_key_exists('changes', $entry)) {
    if (!is_array($entry['changes']) || !array_is_list($entry['changes'])) return false;
    foreach ($entry['changes'] as $line) {
      if (!is_string($line)) return false;
    }
  }
  if (array_key_exists('changesUrl', $entry) && !is_string($entry['changesUrl'])) return false;
  if (array_key_exists('changesCut', $entry) && !is_bool($entry['changesCut'])) return false;

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
    // array_is_list() rather than comparing keys against range(): range(0, -1)
    // counts downwards and yields [0, -1], so the hand-rolled version rejects
    // an empty list — which would have invalidated the whole map, and with it
    // every other service's history, over a service key holding nothing.
    if (!array_is_list($list)) return false;
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
 * capped at the retention setting (staxx_update_settings()['retain']).
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

  // Optional and additive: written only when there is something to write, so
  // an entry with no notes stays exactly the small shape it always was.
  if (is_string($meta['notes'] ?? null) && $meta['notes'] !== '') {
    $entry['notes'] = $meta['notes'];
    $entry['notesCut'] = (bool)($meta['notesCut'] ?? false);
  }
  if (is_string($meta['notesUrl'] ?? null) && $meta['notesUrl'] !== '') {
    $entry['notesUrl'] = $meta['notesUrl'];
  }
  // Same terms again for what went into the build. The commit is worth
  // storing on its own even with no changes to show, because it is what the
  // next recorded entry compares against.
  if (is_string($meta['revision'] ?? null) && $meta['revision'] !== '') {
    $entry['revision'] = $meta['revision'];
  }
  if (is_array($meta['changes'] ?? null) && $meta['changes'] !== []) {
    $entry['changes'] = $meta['changes'];
    $entry['changesCut'] = (bool)($meta['changesCut'] ?? false);
  }
  if (is_string($meta['changesUrl'] ?? null) && $meta['changesUrl'] !== '') {
    $entry['changesUrl'] = $meta['changesUrl'];
  }

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
 * image cleanup want.
 */
function staxx_image_history_digests(string $stack, string $service): array {
  return array_column(staxx_image_history($stack, $service), 'digest');
}

/**
 * Every stack-and-service that has recorded image history, in the exact
 * '<stack>::<service>' => [digests...] shape the image cleanup reads.
 *
 * SAFETY-CRITICAL: the cleanup calls this to decide which images are still
 * wanted for a rollback before it deletes anything. This must walk every
 * stack that actually exists, not a cached or partial list — a digest
 * missing from this answer is an image that gets removed while a rollback
 * still needs it. The map is the scan's own set of stacks, so that promise
 * still holds.
 */
function staxx_image_history_all(): array {
  $all = [];
  foreach (array_keys(staxx_stack_compose_map()) as $rel) {
    $images = staxx_record_read($rel)['images'] ?? [];
    foreach ($images as $service => $list) {
      $digests = array_column($list, 'digest');
      if ($digests) $all[$rel.'::'.$service] = $digests;
    }
  }
  return $all;
}
?>
