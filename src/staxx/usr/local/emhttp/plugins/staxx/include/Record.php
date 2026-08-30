<?PHP
/* StaXX — per-stack history.
 * Copyright 2026, StaXX contributors.
 *
 * Every stack folder can hold a hidden ".staxx" directory: a copy of the
 * compose file as it stood before each save, plus an index of what was kept.
 * NOTHING IN THE PLUGIN MAY EVER REQUIRE THIS TO EXIST. Delete it by hand and
 * the stack must still list, edit, start and stop exactly as before, losing
 * only its history — every read here is best-effort, and no function in this
 * file may throw, warn, or return a fatal. A missing, truncated or hand-
 * mangled record means "no history", never an error the person sees. This is
 * what keeps rule 1 true: the folder stays a plain compose folder that runs
 * anywhere, with or without this file.
 *
 * Versions are numbered sequentially and the number never goes backwards —
 * NOT ordered by time. A server that boots without a network and then reaches
 * a time server steps its clock backwards, which would otherwise write a
 * version that sorts before one already on disk. The stored timestamp is
 * information only and is never used for ordering.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_RECORD_KEEP')) return;

// The newest unnamed automatic saves kept before pruning starts dropping the
// oldest of them. A named version never counts against this.
define('STAXX_RECORD_KEEP', 20);

/**
 * The absolute path to a stack's history directory. Pure string-building —
 * MUST NOT create anything, so a caller that only wants to know where the
 * record would live (to check is_dir(), say) never has the side effect of
 * making it real.
 */
function staxx_record_dir(string $rel): string {
  return staxx_stack_dir($rel).'/.staxx';
}

/** Where one version's bytes live, given its number. Fixed extension: the
 * original file's own name (which can vary — compose.yaml, docker-
 * compose.yml, …) is recorded in the index instead, so history storage
 * itself does not need to guess or preserve it. */
function staxx_record_history_path(string $rel, int $n): string {
  return staxx_record_dir($rel).'/history/'.sprintf('%04d', $n).'.yaml';
}

/**
 * Is this a decoded version entry shaped exactly the way one must be? Every
 * field's type is checked, not just its presence — this file is as hand-
 * editable as the rest of the stack folder, and a wrong-typed field reaching
 * a caller as though it were trustworthy is how a warning or a fatal happens
 * three functions away from here.
 */
function staxx_record_valid_entry($entry): bool {
  if (!is_array($entry)) return false;
  if (!array_key_exists('n', $entry) || !array_key_exists('at', $entry)
    || !array_key_exists('size', $entry) || !array_key_exists('hash', $entry)
    || !array_key_exists('file', $entry) || !array_key_exists('name', $entry)) {
    return false;
  }
  if (!is_int($entry['n']) || $entry['n'] < 1) return false;
  if (!is_int($entry['at']) || $entry['at'] < 0) return false;
  if (!is_int($entry['size']) || $entry['size'] < 0) return false;
  if (!is_string($entry['hash']) || !preg_match('/^[0-9a-f]{64}$/', $entry['hash'])) return false;
  if (!is_string($entry['file']) || $entry['file'] === '') return false;
  if (!is_string($entry['name'])) return false;
  return true;
}

/**
 * Is "versions" itself shaped the way it must be — a plain list where every
 * entry passes staxx_record_valid_entry()? Split out of staxx_record_read()
 * so it can be checked independently of "images" (PLAN_82): a hand-edited
 * or corrupted half of the record must never take the other half down with
 * it. See staxx_image_history_valid_map() in ImageHistory.php, which gets
 * the same treatment for the other half.
 */
function staxx_record_valid_versions($versions): bool {
  if (!is_array($versions)) return false;

  // Must be a plain list — a hand-edited file could easily turn this into a
  // map keyed by version number, which json_decode would still accept as an
  // array but which nothing downstream expects.
  if (array_keys($versions) !== range(0, count($versions) - 1)) return false;

  foreach ($versions as $entry) {
    if (!staxx_record_valid_entry($entry)) return false;
  }
  return true;
}

/**
 * The decoded index, or [] for anything at all that is not a well-formed
 * record at all — missing, unreadable, invalid JSON, or a wrong or missing
 * "v" or "next". Below that, "versions" and "images" (PLAN_82) are each
 * validated on their own and each degrades to [] independently rather than
 * invalidating the whole read: a bad entry in one is never allowed to lose
 * the other, which is otherwise a well-formed half of the same file.
 */
function staxx_record_read(string $rel): array {
  $path = staxx_record_dir($rel).'/record.json';
  if (!@is_file($path)) return [];

  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return [];

  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  if (($data['v'] ?? null) !== 1) return [];
  if (!is_int($data['next'] ?? null) || $data['next'] < 1) return [];

  $versions = staxx_record_valid_versions($data['versions'] ?? null) ? $data['versions'] : [];

  // staxx_image_history_valid_map() lives in ImageHistory.php, which not
  // every caller of this file loads — the compose-edit history has to keep
  // working with that file never included at all, which is exactly the
  // independence this guards.
  $images = function_exists('staxx_image_history_valid_map')
    && staxx_image_history_valid_map($data['images'] ?? null)
    ? $data['images'] : [];

  return ['v' => 1, 'next' => $data['next'], 'versions' => $versions, 'images' => $images];
}

/**
 * Write one file into the record directory atomically — a temporary name in
 * the same directory, then rename() over the target — so a reader never sees
 * a half-written index or a truncated history file. Returns false on any
 * failure and cleans up its own temporary file; never throws or warns.
 */
function staxx_record_atomic_write(string $path, string $data): bool {
  $tmp = $path.'.'.getmypid().'.tmp';
  $written = @file_put_contents($tmp, $data);
  if ($written === false || $written !== strlen($data)) {
    @unlink($tmp);
    return false;
  }
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }
  return true;
}

/**
 * Encode and write the index. The one place record.json is ever written.
 *
 * Callers that only touch the compose-edit history (staxx_record_capture(),
 * staxx_record_name(), staxx_record_prune()) know nothing about "images"
 * (PLAN_82) and never pass it — so whatever is already on disk is carried
 * forward unless the caller explicitly overrides it, meaning a plain history
 * save can never wipe out a stack's image history, or vice versa. Omitted
 * entirely when empty, so a record that has never recorded an image keeps
 * exactly the shape it always had.
 */
function staxx_record_write_index(string $rel, array $record): bool {
  if (!array_key_exists('images', $record)) {
    $record['images'] = staxx_record_read($rel)['images'] ?? [];
  }
  $out = ['v' => 1, 'next' => $record['next'], 'versions' => $record['versions']];
  if ($record['images']) $out['images'] = $record['images'];

  $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  return staxx_record_atomic_write(staxx_record_dir($rel).'/record.json', $json."\n");
}

/**
 * The heart of it: keep a copy of $file's CURRENT contents before it gets
 * overwritten. Called from both write paths — the form/compose-view save and
 * a direct file edit — so history has no doors that quietly skip it.
 *
 * Reads from disk, never from anything a caller believes it opened: if
 * somebody edited the file on the server while a tab was open, the version
 * kept here is the one genuinely about to be lost, which is the one that
 * matters.
 *
 * Always returns true unless the save itself should be told history was not
 * kept — a capture failure must never block the save that triggered it. On
 * failure $note carries one plain sentence for the person; the caller shows
 * it as a warning after saving, not as a refusal to save.
 */
function staxx_record_capture(string $rel, string $file, string &$note): bool {
  $note = '';

  // A basename, never a path. Handed a full path this would look for a file
  // that cannot exist, find nothing to keep, and report success — which is
  // exactly how the first wiring of this shipped with no history at all and
  // nothing complaining. Fail loudly instead.
  if ($file === '' || strpos($file, '/') !== false) {
    $note = 'The previous version could not be kept, so this save cannot be undone from the history.';
    return false;
  }

  $src = staxx_stack_dir($rel).'/'.$file;

  // Nothing on disk yet (a brand new stack's first save) — there is nothing
  // to keep, and that is not a failure.
  if (!@is_file($src)) return true;

  // Read once and hash what was read. Hashing the file and then reading it
  // separately is two reads of something a person can edit between them, and
  // a stored copy whose recorded hash does not match its own bytes reads back
  // as corrupt for ever after.
  $bytes = @file_get_contents($src);
  if ($bytes === false) {
    $note = 'The previous version could not be read, so this save cannot be undone from the history.';
    return false;
  }
  $hash = hash('sha256', $bytes);

  $record   = staxx_record_read($rel);
  $versions = $record['versions'] ?? [];
  $next     = $record['next'] ?? 1;

  // The newest kept version by number, not by position — belt and braces,
  // since staxx_record_read() already only accepts a plain list.
  $newest = null;
  foreach ($versions as $v) {
    if ($newest === null || $v['n'] > $newest['n']) $newest = $v;
  }

  // A save that changed nothing is not a version.
  if ($newest !== null && $newest['hash'] === $hash) return true;

  $histDir = staxx_record_dir($rel).'/history';
  if (!@is_dir($histDir) && !@mkdir($histDir, 0755, true)) {
    $note = 'The previous version could not be kept, so this save cannot be undone from the history.';
    return false;
  }

  $n = $next;
  if (!staxx_record_atomic_write(staxx_record_history_path($rel, $n), $bytes)) {
    $note = 'The previous version could not be kept, so this save cannot be undone from the history.';
    return false;
  }

  $versions[] = [
    'n'    => $n,
    'at'   => time(),
    'size' => strlen($bytes),
    'hash' => $hash,
    'file' => $file,
    'name' => '',
  ];

  $ok = staxx_record_write_index($rel, ['v' => 1, 'next' => $n + 1, 'versions' => $versions]);
  if (!$ok) {
    @unlink(staxx_record_history_path($rel, $n)); // orphaned copy is no use without its index entry
    $note = 'The previous version could not be kept, so this save cannot be undone from the history.';
    return false;
  }

  staxx_record_prune($rel);

  $note = '';
  return true;
}

/**
 * For a compose file that reached this stack some way other than a StaXX
 * save — dropped into the folder by hand, most often — keep the file as it
 * stands now, but only if this stack's history holds nothing at all. Once
 * any version exists, whether from this or from an ordinary save, there is
 * something to find and this is a no-op that still reports success: it can
 * therefore only ever do real work once in a stack's life.
 *
 * Reuses staxx_record_capture() rather than a second path to the same
 * history directory — that function already reads the file fresh off disk,
 * hashes it, and skips a capture that duplicates the newest kept version, so
 * there is nothing left for this to do but check the history is empty first.
 */
function staxx_record_seed(string $rel, string &$note): bool {
  $note = '';
  if (staxx_record_read($rel)['versions'] ?? []) return true; // already has history

  $file = staxx_find_compose_file(staxx_stack_dir($rel));
  if ($file === '') return true; // no compose file to seed from

  return staxx_record_capture($rel, basename($file), $note);
}

/** Every kept version, newest first. [] when there is no history at all. */
function staxx_record_list(string $rel): array {
  $versions = staxx_record_read($rel)['versions'] ?? [];
  usort($versions, fn($a, $b) => $b['n'] <=> $a['n']);
  return $versions;
}

/**
 * The stored bytes of one version, or null if the index does not name it, its
 * file is gone, or what is on disk no longer matches the hash recorded for
 * it — a version that is not what was recorded is not a version.
 */
function staxx_record_get(string $rel, int $n): ?string {
  $versions = staxx_record_read($rel)['versions'] ?? [];

  $entry = null;
  foreach ($versions as $v) {
    if ($v['n'] === $n) { $entry = $v; break; }
  }
  if ($entry === null) return null;

  $path = staxx_record_history_path($rel, $n);
  if (!@is_file($path)) return null;

  // Read once, then hash what was read. Hashing the file and reading it
  // again is two reads of the same thing, and the whole point of this check
  // is to be sure the bytes handed back are the bytes that were recorded.
  $bytes = @file_get_contents($path);
  if ($bytes === false) return null;
  return hash('sha256', $bytes) === $entry['hash'] ? $bytes : null;
}

/**
 * Set or clear one version's name. An empty label clears it, which puts the
 * version back in the ordinary rotation and may see it pruned immediately —
 * the caller is expected to warn about that beforehand, since this function
 * simply does it.
 */
function staxx_record_name(string $rel, int $n, string $label, string &$error): bool {
  $error = '';

  if (strlen($label) > 60) {
    $error = 'A version name can be at most 60 characters.';
    return false;
  }
  if (preg_match('/[\x00-\x1F\x7F]/', $label)) {
    $error = 'A version name cannot contain control characters.';
    return false;
  }

  $record = staxx_record_read($rel);
  $versions = $record['versions'] ?? [];
  if (!$versions) {
    $error = 'No such version.';
    return false;
  }

  $found = false;
  foreach ($versions as &$v) {
    if ($v['n'] === $n) { $v['name'] = $label; $found = true; break; }
  }
  unset($v);

  if (!$found) {
    $error = 'No such version.';
    return false;
  }

  if (!staxx_record_write_index($rel, ['v' => 1, 'next' => $record['next'], 'versions' => $versions])) {
    $error = 'Could not save the change to this version\'s name.';
    return false;
  }

  // Only clearing a name can put a version back into the pruned queue; giving
  // one a name can never make the kept list too long.
  if ($label === '') staxx_record_prune($rel);

  return true;
}

/**
 * Keep the newest 20 unnamed versions; a named version is never pruned and
 * does not count towards that limit. An index entry whose history file has
 * already vanished is dropped too, named or not — it is not a version
 * against a file that no longer exists.
 *
 * Best-effort throughout: if the pruned index cannot be written, nothing is
 * deleted and the record is left exactly as it was, since deleting first and
 * failing to write second would lose files the index still names.
 */
function staxx_record_prune(string $rel): void {
  $record = staxx_record_read($rel);
  $versions = $record['versions'] ?? [];
  if (!$versions) return;

  $present = [];
  $vanished = [];
  foreach ($versions as $v) {
    if (@is_file(staxx_record_history_path($rel, $v['n']))) $present[] = $v;
    else $vanished[] = $v;
  }

  $named   = array_values(array_filter($present, fn($v) => $v['name'] !== ''));
  $unnamed = array_values(array_filter($present, fn($v) => $v['name'] === ''));
  usort($unnamed, fn($a, $b) => $b['n'] <=> $a['n']); // newest first

  $keepUnnamed = array_slice($unnamed, 0, STAXX_RECORD_KEEP);
  $dropUnnamed = array_slice($unnamed, STAXX_RECORD_KEEP);

  if (!$vanished && !$dropUnnamed) return; // nothing to change

  $kept = array_merge($named, $keepUnnamed);
  usort($kept, fn($a, $b) => $a['n'] <=> $b['n']); // stored ascending, as capture writes them

  $ok = staxx_record_write_index($rel, ['v' => 1, 'next' => $record['next'], 'versions' => $kept]);
  if (!$ok) return; // left exactly as it was; nothing on disk was touched

  foreach ($dropUnnamed as $v) @unlink(staxx_record_history_path($rel, $v['n']));
}
?>
