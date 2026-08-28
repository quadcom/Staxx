<?PHP
/* StaXX — moving the stacks folder from one location to another, safely.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_68 Part B: the flash drive has finite write cycles and no redundancy,
 * so a stack's home may need to move to a pool or the overlay. This is the
 * machinery that does the moving. No page and no endpoint reach it yet — it
 * exists here, on its own, so it can be proved correct before anything can
 * reach it by accident.
 *
 * The shape is fixed and does not change per stack: fingerprint the source,
 * prove the destination can hold the same shape with a trial run of empty
 * placeholders, copy everything, verify everything against what actually
 * landed on disk, switch the stacks folder setting — the point after which
 * the move cannot be undone by discarding the copy — then remove the old
 * folders. Never a rename: across two
 * filesystems that is already a copy and a delete with no gate in between,
 * which is exactly what this file exists to add.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';

if (defined('STAXX_RELOCATE_LOADED')) return;
define('STAXX_RELOCATE_LOADED', true);

// The folder name suggested inside an existing share. Named for the plugin
// rather than called "stacks", because it is offered inside somebody's real
// appdata share and "stacks" is a name other compose tools plausibly already
// own there — a suggestion that collides is refused later for holding
// something, which is safe but a poor thing to offer in the first place.
define('STAXX_STACKS_FOLDER', 'staxx-stacks');

/**
 * Is this a symlink's target reachable, and does it lie outside the stacks
 * tree? Answered only to note it in the report — the move never follows a
 * link either way, so this changes nothing about what gets copied, only
 * what the person is told about afterwards.
 *
 * A target that cannot be resolved at all (the link is already broken, or
 * points at something this process cannot see) is treated as outside: there
 * is nothing to vouch for it being inside the tree, so the honest answer is
 * "not known to be moving with everything else".
 */
function staxx_relocate_link_outside(string $linkDir, string $target, string $root): bool {
  if ($target === '') return true;
  $resolved = $target[0] === '/' ? $target : $linkDir.'/'.$target;
  $real = @realpath($resolved);
  if ($real === false) return true;
  return $real !== $root && strpos($real, $root.'/') !== 0;
}

/**
 * Is this destination acceptable? Reuses the existing STACK_ROOT path
 * validator rather than writing a second one — it already refuses a
 * relative path, "..", anywhere outside /mnt/ or the plugin's own config
 * folder, a missing parent, and a path that would land on a memory
 * filesystem and vanish at the next reboot. What is added here is specific
 * to a move: the destination must not be the current stacks folder, sit
 * inside it, or contain it, and it must not already hold anything.
 *
 * Free space is deliberately not checked here — it depends on the measured
 * size of the source, which is not known until the source has been scanned,
 * and that scan is the next step staxx_relocate_run() takes after this one.
 *
 * @return string the normalised destination, or '' with $error set when refused
 */
function staxx_relocate_refuse(string $destInput, string &$error, string &$notice = null): string {
  $notice = '';
  $error  = '';
  $source = staxx_stack_root();

  $norm = staxx_settings_validate_path('STACK_ROOT', $destInput, $error);
  if ($error !== '') return '';

  if ($norm === $source || strpos($norm, $source.'/') === 0) {
    $error = 'The new location is the current stacks folder, or sits inside it. Choose somewhere else.';
    return '';
  }
  if (strpos($source, $norm.'/') === 0) {
    $error = 'The new location would contain the current stacks folder, which would put the '
           . 'old folder inside the new one. Choose somewhere else.';
    return '';
  }

  /* Which share is this destination in, and will the mover drain it?
   *
   * Checked here so that a typed or browsed path gets the same care as a
   * suggested one — without this, choosing a share set to move onto the array
   * was accepted with "this path can be used", and the stacks would then have
   * been carried off the pool behind the person's back.
   *
   * The share is the second segment under /mnt, which covers both a pool path
   * (/mnt/cache-small/dvr) and the share-layer form (/mnt/user/dvr) — the
   * mover reads the share's own settings either way, so both have to be asked
   * about.
   */
  if (staxx_placement_guided()) {
    $risk = staxx_placement_risk($norm);
    if ($risk !== '') {
      $error = 'The stacks cannot go here — '.$risk.'. Choose somewhere else, or set the '
             . 'placement rules to "open" in Settings if you mean to do this anyway.';
      return '';
    }
  } else {
    /* The rules are set to get out of the way, but one risk here is not a
     * trade-off, it is a path that stops working.
     *
     * StaXX prefers a direct pool path — /mnt/<pool>/<share>/... — because it
     * skips Unraid's share layer, which is faster and lands the files exactly
     * where you said. But a share the mover drains gets its contents carried
     * off to the array, and a direct pool path then names a folder the data has
     * left: the stacks would simply disappear from the page. The share-layer
     * form of the same folder follows the data wherever the mover puts it, so
     * it keeps working.
     *
     * So this one case is rewritten rather than allowed as-is, and the swap is
     * reported rather than done quietly — it is a real trade-off, and the
     * person choosing the location is entitled to know it was made.
     */
    $swap = staxx_relocate_share_layer_form($norm);
    if ($swap !== '') {
      $notice = 'Saved as '.$swap.' rather than '.$norm.'. StaXX normally writes straight to the '
              . 'pool, which is faster and puts the files exactly where you asked — but Unraid is '
              . 'set to move this share onto the array, and once it does, the pool path would name '
              . 'a folder the stacks had left and they would vanish from this page. Going through '
              . 'the share layer follows them instead. The cost is that the share layer is a little '
              . 'slower, and new files can land on the array rather than the pool.';
      $norm = $swap;
    }
  }

  /* The whole of somebody's share is not a home for the stacks.
   *
   * A path of exactly /mnt/<pool>/<share> or /mnt/user/<share> makes the stack
   * root the share itself, and then every folder in that share reads as a
   * stack — pointed at appdata, every container's config folder would. It was
   * accepted before whenever the share happened to be empty, which is the one
   * case where nothing looked wrong at the time.
   *
   * A share named for StaXX is the exception, and the case worth encouraging:
   * somebody who made a share for this deliberately means its root, and
   * nesting a second folder called staxx-stacks inside a share called staxx
   * would be silly.
   *
   * Refused with the exact path to use instead rather than quietly rewritten —
   * a destination that changes under somebody's typing is worse than one that
   * says what it wants. The suggestion builder offers the same nested shape,
   * so this is the same rule reaching a path that was typed or browsed to.
   */
  if (preg_match('#^/mnt/[^/]+/([^/]+)$#', $norm, $m) && stripos($m[1], 'staxx') === false) {
    $error = 'That is the whole of the "'.$m[1].'" share, and every folder in it would be read '
           . 'as a stack. Use a folder inside it instead — '.$norm.'/'.STAXX_STACKS_FOLDER
           . ' — so the share can go on being used for what it is for.';
    return '';
  }

  if (is_dir($norm)) {
    $entries = @scandir($norm);
    if ($entries === false) {
      $error = 'The new location could not be read, so it could not be checked. Nothing was moved.';
      return '';
    }
    if (count($entries) > 2) { // more than just "." and ".."
      $error = 'The new location already holds something. Choose an empty folder, or a path '
             . 'that does not exist yet.';
      return '';
    }
  }

  return $norm;
}

/**
 * The share-layer form of a direct pool path, but only where using it is the
 * safer choice: /mnt/<pool>/<share>/rest becomes /mnt/user/<share>/rest when
 * the mover is set to drain that share onto the array.
 *
 * '' for everything else, and that includes a path already going through the
 * share layer — which is what makes this safe to apply twice, since the
 * background job re-checks its own destination before touching anything.
 *
 * Not applied to an array-disk path on purpose. That risk is different in kind:
 * /mnt/disk8/... keeps working, it is simply unprotected, so there is nothing
 * for a rewrite to rescue. Only a drained share moves the data out from under
 * the path that names it.
 */
function staxx_relocate_share_layer_form(string $path): string {
  if (!preg_match('#^/mnt/([^/]+)/([^/]+)(/.*)?$#', $path, $m)) return '';
  if ($m[1] === 'user' || $m[1] === 'user0') return '';
  if (staxx_share_drain_reason($m[2]) === '') return '';
  return '/mnt/user/'.$m[2].($m[3] ?? '');
}

/**
 * Walk the stacks folder and build a flat manifest of everything in it — not
 * just the stacks it recognises, but whatever else sits in the tree, so the
 * move can copy the whole thing wholesale. A directory, a file and a
 * symlink are recorded differently because they are verified differently: a
 * symlink is never opened, only its target string is kept.
 *
 * Fails on an UNREADABLE tree, never on an empty one. An unmounted pool or
 * an array that has not started can look exactly like an empty folder from
 * here, and reading that as "there is nothing to move" would let the real
 * stacks folder vanish out from under it. A folder that genuinely has
 * nothing in it is not this case — it reads fine and comes back with an
 * empty manifest, which staxx_relocate_run() moves without complaint.
 *
 * @return array<string, array{type:string, size:int, sha256:string, target:string}>|null
 */
function staxx_relocate_scan(string $root, string &$error): ?array {
  $error = '';
  if (!is_dir($root)) {
    $error = 'The stacks folder could not be found, so it could not be looked at. Nothing was moved.';
    return null;
  }

  $out  = [];
  $walk = function (string $dir, string $rel) use (&$walk, &$out, &$error): void {
    if ($error !== '') return;
    $entries = @scandir($dir);
    if ($entries === false) {
      $error = 'The stacks folder could not be read all the way through — a permission problem, '
             . 'or a pool that is not actually mounted — so it could not be looked at properly. '
             . 'Nothing was moved.';
      return;
    }
    foreach ($entries as $name) {
      if ($name === '.' || $name === '..') continue;
      $path    = $dir.'/'.$name;
      $relPath = $rel === '' ? $name : $rel.'/'.$name;

      if (is_link($path)) {
        $target = @readlink($path);
        $out[$relPath] = [
          'type' => 'link', 'size' => 0, 'sha256' => '', 'target' => $target !== false ? $target : '',
        ];
        continue;
      }
      if (is_dir($path)) {
        $out[$relPath] = ['type' => 'dir', 'size' => 0, 'sha256' => '', 'target' => ''];
        $walk($path, $relPath);
        continue;
      }
      $size = @filesize($path);
      $hash = @hash_file('sha256', $path);
      $out[$relPath] = [
        'type'   => 'file',
        'size'   => $size !== false ? $size : 0,
        'sha256' => $hash !== false ? $hash : '',
        'target' => '',
      ];
    }
  };

  $walk($root, '');
  if ($error !== '') return null;
  return $out;
}

/**
 * Prove the new location can hold the *shape* of the tree before a single
 * byte is copied — every directory as a directory, every file as an empty
 * one, every symlink pointing at the same target text, all inside a
 * uniquely-named folder under the destination. This is what catches a
 * filesystem that cannot make a symlink, a name it refuses outright, two
 * names that only differ by case landing on a case-insensitive filesystem,
 * or a folder that is writable at the top and not further down — every one
 * of which would otherwise only surface partway through the real copy,
 * leaving exactly the half-written mess this whole file exists to prevent.
 *
 * NOT A GUARANTEE: this proves the shape can be created, not that the bytes
 * will land. staxx_relocate_verify() is still what actually decides that,
 * and nothing here shortens or replaces it.
 *
 * The destination may not exist yet at this point — normally the copy step
 * is what creates it — so this creates it here too, rather than inventing a
 * second place for the proof to live. On any failure the whole destination
 * is removed with the existing cleanup helper, the same as a failed copy or
 * a failed verify does; on success only the trial folder is removed, since a
 * leftover would make the destination look like it "already holds
 * something" and refuse the next attempt at the very check this file starts
 * with.
 *
 * A name is checked for a collision *before* it is created, not because
 * Unraid's own array or pool filesystems are case-blind — xfs, btrfs and
 * zfs all tell "Demo" and "demo" apart. The check earns its place for two
 * other reasons: it catches the scan above ever having produced the same
 * relative path twice, which would otherwise let the second placeholder
 * silently overwrite the first while the real copy quietly moved one file
 * fewer than it reported; and a destination reached through Unassigned
 * Devices or a mounted remote share can genuinely be NTFS, exFAT, or
 * Windows-backed, where two names differing only in case really do collide.
 *
 * @param array<string, array{type:string, size:int, sha256:string, target:string}> $manifest
 */
function staxx_relocate_trial(array $manifest, string $dest, string &$error): bool {
  $error = '';
  if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
    $error = 'Could not create the new location "'.$dest.'" to test whether it can hold everything. '
           . 'Nothing was moved.';
    return false;
  }

  $trial = $dest.'/.staxx-relocate-trial-'.bin2hex(random_bytes(8));
  if (file_exists($trial) || is_link($trial) || !@mkdir($trial, 0755, true)) {
    $error = 'Could not create a trial folder inside "'.$dest.'" to test whether it can hold everything. '
           . 'Nothing was moved.';
    // Nothing of ours exists yet — the trial folder is what just failed to be
    // made. Whether $dest itself should go is the caller's call, not this
    // function's, because only the caller knows whether it made it.
    return false;
  }

  foreach ($manifest as $rel => $info) {
    $path = $trial.'/'.$rel;

    if (file_exists($path) || is_link($path)) {
      $error = '"'.$rel.'" collides with another entry already placed at the new location — either '
             . 'two names that only differ in case, on a filesystem that treats them as the same '
             . 'name, or the same path appearing twice in what was read from the source. '
             . 'Nothing was moved.';
      staxx_relocate_cleanup($trial);
      return false;
    }

    if ($info['type'] === 'dir') {
      if (!@mkdir($path, 0755, true)) {
        $error = 'Could not create the folder "'.$rel.'" at the new location while testing it. Nothing was moved.';
        staxx_relocate_cleanup($trial);
        return false;
      }
      continue;
    }
    if ($info['type'] === 'link') {
      if (!@symlink($info['target'], $path)) {
        $error = 'Could not create the link "'.$rel.'" at the new location while testing it. Nothing was moved.';
        staxx_relocate_cleanup($trial);
        return false;
      }
      continue;
    }

    // A plain file, created empty — no content is written during the trial,
    // which is what keeps this cheap even for a large stacks folder.
    $handle = @fopen($path, 'x');
    if ($handle === false) {
      $error = 'Could not create the file "'.$rel.'" at the new location while testing it. Nothing was moved.';
      staxx_relocate_cleanup($trial);
      return false;
    }
    fclose($handle);
  }

  staxx_relocate_cleanup($trial);
  return true;
}

/**
 * Copy the whole stacks tree to the new location, wholesale — every file the
 * tool understands and every file it does not, because a stack is a
 * directory and whatever else is in it has to travel too. A symlink is
 * recreated as a symlink pointing at the exact same text; it is never
 * followed, since that would turn a link the author wrote into a copy of
 * whatever it happens to point at, possibly enormous, possibly outside the
 * tree entirely.
 */
function staxx_relocate_copy_tree(string $src, string $dst, string &$error): bool {
  $error = '';
  if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
    $error = 'Could not create the folder "'.$dst.'".';
    return false;
  }

  $entries = @scandir($src);
  if ($entries === false) {
    $error = 'Could not read "'.$src.'" during the copy.';
    return false;
  }

  foreach ($entries as $name) {
    if ($name === '.' || $name === '..') continue;
    $from = $src.'/'.$name;
    $to   = $dst.'/'.$name;

    if (is_link($from)) {
      $target = @readlink($from);
      if ($target === false || !@symlink($target, $to)) {
        $error = 'Could not recreate the link "'.$name.'" at the new location.';
        return false;
      }
      continue;
    }
    if (is_dir($from)) {
      if (!staxx_relocate_copy_tree($from, $to, $error)) return false;
      continue;
    }
    if (!@copy($from, $to)) {
      $error = 'Could not copy "'.$name.'" to the new location.';
      return false;
    }
  }

  return true;
}

/**
 * Does the copy actually match, byte for byte? Every check here reads the
 * destination fresh off disk — never a value the copy step believed it
 * wrote — because a file count and a size match is not verification, and
 * must never be described as though it were. A file's content is rehashed
 * here; a symlink's target string is compared, not followed.
 *
 * @param array<string, array{type:string, size:int, sha256:string, target:string}> $manifest
 * @return string[] one line per problem found; empty means the copy verified clean
 */
function staxx_relocate_verify(array $manifest, string $dst): array {
  $problems = [];

  foreach ($manifest as $rel => $info) {
    $path = $dst.'/'.$rel;

    if ($info['type'] === 'link') {
      if (!is_link($path)) { $problems[] = $rel.': is not a link at the new location.'; continue; }
      $target = @readlink($path);
      if ($target !== $info['target']) {
        $problems[] = $rel.': the link points somewhere different at the new location.';
      }
      continue;
    }

    if ($info['type'] === 'dir') {
      if (is_link($path) || !is_dir($path)) $problems[] = $rel.': the folder is missing at the new location.';
      continue;
    }

    // A plain file.
    if (is_link($path) || !is_file($path)) { $problems[] = $rel.': is missing at the new location.'; continue; }
    $size = @filesize($path);
    if ($size !== $info['size']) {
      $problems[] = $rel.': is '.$size.' byte(s) at the new location, expected '.$info['size'].'.';
      continue;
    }
    $hash = @hash_file('sha256', $path);
    if ($hash !== $info['sha256']) {
      $problems[] = $rel.': its content does not match — the copy is not byte-for-byte identical.';
    }
  }

  return $problems;
}

/**
 * Remove a just-copied destination that turned out not to verify, or that a
 * later step failed after. staxx_rmtree() checks every path it descends into
 * resolves inside $root, so $root has to be the same realpath()'d value as
 * the path being removed — everywhere else in the plugin that calls it does
 * the same, and a plain, unresolved $dest here would make that containment
 * check compare two different strings for what is really the same place.
 */
function staxx_relocate_cleanup(string $dest, bool $keepFolder = false): void {
  $real = @realpath($dest);
  if ($real === false) return;

  if (!$keepFolder) { staxx_rmtree($real, $real); return; }

  // Somebody made this folder themselves and pointed the move at it. What we
  // put inside it is ours to take back; the folder is not, and removing it
  // would be destroying something they created to answer a failure that was
  // ours. Its contents go, it stays.
  foreach ((array)@scandir($real) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    staxx_rmtree($real.'/'.$entry, $real);
  }
}

/**
 * Move every stack, wholesale, to a new location.
 *
 * The order is fixed and is the whole point of this file: copy, verify,
 * switch the setting, delete. Everything before the setting switch is
 * reversible by simply discarding the copy — a failed copy or a failed
 * verify removes the partial copy and leaves the source untouched. Once the
 * setting is switched, the stacks are already live at the new location, so a
 * failure removing the old folders is reported, not treated as the move
 * having failed.
 *
 * $log is called once per line of progress, in full sentences — the
 * detached script echoes each line straight into its job log; the test
 * suite passes a closure that collects them into an array instead.
 */
function staxx_relocate_run(string $destInput, callable $log, string &$error): bool {
  $error  = '';
  $source = staxx_stack_root();

  $log('Checking the new location.');
  $dest = staxx_relocate_refuse($destInput, $error);
  if ($dest === '') { $log('Refused: '.$error); return false; }

  // Remembered before anything is created, because every failure below has to
  // put the destination back as it was found — and "as it was found" is a
  // folder somebody may have made themselves and pointed this at. Removing
  // that to report our own failure would be destroying something of theirs.
  $destExisted = is_dir($dest);

  $log('Looking at the current stacks folder.');
  $manifest = staxx_relocate_scan($source, $error);
  if ($manifest === null) { $log('Refused: '.$error); return false; }

  $rootReal = @realpath($source) ?: $source;
  $fileCount = 0;
  $totalSize = 0;
  $outsideLinks = [];
  foreach ($manifest as $rel => $info) {
    if ($info['type'] === 'file') { $fileCount++; $totalSize += $info['size']; }
    if ($info['type'] === 'link') {
      $linkDir = dirname($rootReal.'/'.$rel);
      if (staxx_relocate_link_outside($linkDir, $info['target'], $rootReal)) $outsideLinks[] = $rel;
    }
  }
  $log('Found '.$fileCount.' file(s) totalling '.$totalSize.' byte(s) to move.');
  foreach ($outsideLinks as $rel) {
    $log('Note: "'.$rel.'" is a link pointing outside the stacks folder — its target is not being moved.');
  }

  $spaceCheckPath = is_dir($dest) ? $dest : dirname($dest);
  $free = @disk_free_space($spaceCheckPath);
  if ($free === false) {
    $error = 'Could not measure the free space at the new location, so nothing was moved.';
    $log('Refused: '.$error);
    return false;
  }
  if ($free < $totalSize) {
    $error = 'The new location has '.$free.' byte(s) free, but the move needs '.$totalSize
           . ' byte(s). Nothing was moved.';
    $log('Refused: '.$error);
    return false;
  }

  $log('Testing whether the new location can hold everything (no files copied yet).');
  if (!staxx_relocate_trial($manifest, $dest, $error)) {
    // The trial clears up after itself, but it may have created the
    // destination folder to work in — so the same put-it-back-as-found rule
    // every other failure here follows applies to this one too.
    staxx_relocate_cleanup($dest, $destExisted);
    $log('Refused: '.$error);
    $log('Nothing was copied, and the current stacks folder was not touched.');
    return false;
  }
  $log('The new location can hold everything. Nothing has been copied yet.');

  $log('Copying every stack to the new location.');
  if (!staxx_relocate_copy_tree($source, $dest, $error)) {
    $log('The copy failed: '.$error);
    staxx_relocate_cleanup($dest, $destExisted);
    $log('The partial copy has been removed. Nothing at the current location was touched.');
    return false;
  }

  $log('Checking every file matches, byte for byte.');
  $problems = staxx_relocate_verify($manifest, $dest);
  if ($problems) {
    foreach ($problems as $p) $log('Verification problem: '.$p);
    staxx_relocate_cleanup($dest, $destExisted);
    $error = 'Verification found '.count($problems).' problem(s) with the copy; see the lines above. '
           . 'The current stacks folder was left untouched and the partial copy was removed.';
    $log($error);
    return false;
  }
  $log('Every file verified — the same files, the same sizes, the same content.');

  $log('Switching the stacks folder setting to the new location.');
  $saveError = '';
  if (!staxx_settings_save(['STACK_ROOT' => $dest], $saveError)) {
    staxx_relocate_cleanup($dest, $destExisted);
    $error = 'Could not switch the stacks folder setting ('.$saveError.'), so the copy has been '
           . 'removed and nothing has changed. Your stacks are still where they were.';
    $log($error);
    return false;
  }
  $log('The stacks folder is now "'.$dest.'". Your stacks are already live there.');

  $log('Removing the old folders.');
  $oldReal = @realpath($source);
  if ($oldReal === false || !staxx_rmtree($oldReal, $oldReal)) {
    // The commit point has already passed — the stacks are safe at the new
    // location, so a failure here is a tidy-up left undone, not a failed move.
    $log('Could not fully remove the old folder at "'.$source.'". Your stacks are safe at the new '
       . 'location; remove "'.$source.'" by hand once you have checked it is no longer needed.');
    return true;
  }

  $log('Done. Your stacks are now at "'.$dest.'", and the old folder has been removed.');
  return true;
}

/**
 * Start a move as a detached job, the same shape staxx_start_handover() uses:
 * a job id the existing poller can follow, a log that opens with the command
 * actually run, and a "STAXX_JOB_END <exit code>" sentinel appended once it
 * finishes so the poller knows the run is over.
 *
 * The refusal checks run here too, before anything is started, so an
 * obviously bad destination is reported straight away rather than only after
 * a job has been created for it — but scripts/relocate.php re-checks
 * everything again from scratch once it is running, since it runs as root
 * and nothing may assume its own argument arrived unchanged.
 */
function staxx_relocate_start(string $destInput, string &$error): string {
  $error = '';

  $dest = staxx_relocate_refuse($destInput, $error);
  if ($dest === '') return '';

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  $phpCmd = 'php '.escapeshellarg(STAXX_ROOT.'/scripts/relocate.php').' '.escapeshellarg($dest);
  $inner  = $phpCmd.'; echo "'.STAXX_JOB_END.' $?"';

  @file_put_contents($log, '$ '.$phpCmd."\n\n");
  @chmod($log, 0600);

  @exec('setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/* -------------------------------------------------------- storage options -- */

/**
 * PLAN_68 Part B, piece 3: what locations a person could actually move the
 * stacks folder to. Reads Unraid's own disk state rather than guessing at
 * it — nothing here invents a share, a filesystem, or a redundancy claim
 * that was not read from somewhere. No chooser and no page reach this yet;
 * it only answers "what is there", the same one-piece-at-a-time approach
 * staxx_relocate_run() itself was built with.
 *
 * A quoted-section ini file — disks.ini and a share's own .cfg share this
 * shape — read with every value already unquoted by the raw scanner (see
 * staxx_cfg()'s own comment on why RAW is used everywhere in this plugin)
 * and every section name stripped of the quotes disks.ini wraps around them,
 * so a caller sees "m2cache" rather than the literal string with the quote
 * marks still on it.
 *
 * @return array<string, array<string, string>>
 */
function staxx_storage_ini_sections(string $path): array {
  $raw = @parse_ini_file($path, true, INI_SCANNER_RAW);
  if ($raw === false) return [];
  $out = [];
  foreach ($raw as $name => $info) {
    if (is_array($info)) $out[trim((string)$name, '"')] = $info;
  }
  return $out;
}

/**
 * Is $profile a redundancy Unraid would actually stand behind — mirror, or
 * any RAIDZ level? An empty profile is NOT the opposite of this: it means
 * the pool never reported one, which is "not reported as redundant", never
 * worded as "not redundant" — so every caller keeps $profile alongside this
 * flag rather than presenting the flag alone.
 */
function staxx_storage_redundant(string $profile): bool {
  return $profile === 'mirror' || preg_match('/^raidz/i', $profile) === 1;
}

/**
 * Would Unraid's mover leave a folder on this pool alone, or carry it back
 * onto the array? Read from the share's own config, never assumed — "yes"
 * drains it onto the array, while "prefer", "only", "no" and a share with no
 * config file at all all leave it where it is. A config naming a different
 * pool as its cache target is the one other refusal: the share's files live
 * somewhere else entirely.
 *
 * @return string '' when the share can be trusted to stay on $pool, else why not.
 */
function staxx_storage_share_reason(string $sharesDir, string $shareName, string $pool): string {
  /* No settings file, or one that does not name a cache policy, is SAFE — not
   * unknown. Unraid's own mover is why: it loops over
   * /boot/config/shares/*.cfg and nothing else, so a share with no file there
   * is never examined at all, and a file that does not set shareUseCache
   * sources an empty value matching neither case below. Both were reported
   * here as "whether the mover would carry it off this pool is not known",
   * which was a confident wrong answer in the frightening direction.
   */
  /* This one is read on its own in the "Not offered" list, so it is the caller
   * that turns the clause into a sentence and says what to do about it. */
  $drain = staxx_share_drain_reason($shareName, $sharesDir);
  if ($drain !== '') {
    return ucfirst($drain).'. Set that share to "Only" or "Prefer" on its pool on the Shares '
         . 'page, or choose somewhere else.';
  }

  $cfg = @parse_ini_file($sharesDir.'/'.$shareName.'.cfg', false, INI_SCANNER_RAW);
  if ($cfg === false || !isset($cfg['shareUseCache'])) return '';

  $cachePool = trim((string)($cfg['shareCachePool'] ?? ''), '"');
  if ($cachePool !== '' && $cachePool !== $pool) {
    return 'The "'.$shareName.'" share keeps its files on a different pool ("'.$cachePool.'"), not this one.';
  }

  return '';
}

/**
 * What locations could the stacks folder actually move to, read entirely
 * from Unraid's own records — never a guess dressed up as an option.
 *
 * A pool is only ever offered when it is genuinely mounted, holds an
 * existing top-level folder matching the appdata share Unraid itself names
 * (never a new one — staxx_folder_create()'s own comment explains why
 * inventing a share is refused everywhere else in this plugin, and this is
 * no exception), and that share's own policy keeps files on this pool
 * rather than letting the mover carry them onto the array. Every candidate
 * path still has to pass the existing STACK_ROOT validator, which is what
 * actually refuses a memory filesystem, a missing parent or anything
 * outside /mnt — nothing here re-implements that check.
 *
 * A member device of a pool reports the same type as the pool itself but
 * carries no fsStatus at all; that absence is the only reliable way to
 * tell the two apart, so it is the rule used here.
 *
 * $disksIni and $sharesDir exist only so a test can point them at fixtures,
 * the same reasoning staxx_watch_template_claims() takes its directory for —
 * every real caller in this plugin omits both and gets Unraid's real files.
 *
 * @return array{offered: array<int, array{kind:string, name:string, path:string,
 *   fsType:string, freeBytes:?int, fsProfile:string, redundant:bool, share:string,
 *   removable:?bool}>, unavailable: array<int, array{kind:string, name:string, reason:string}>}
 */
/**
 * Are these two paths the same directory, one of them possibly reached
 * through Unraid's share layer? True for an exact match, and true when the
 * two differ only in that one starts /mnt/user and the other /mnt/<pool>
 * with an identical tail. Two different pools sharing a tail are NOT the
 * same place, which is why the share layer has to be one of the two sides.
 */
function staxx_storage_same_place(string $a, string $b): bool {
  if ($a === $b) return true;
  if (!preg_match('#^/mnt/([^/]+)/(.+)$#', $a, $ma)) return false;
  if (!preg_match('#^/mnt/([^/]+)/(.+)$#', $b, $mb)) return false;
  if ($ma[2] !== $mb[2]) return false;
  return ($ma[1] === 'user') !== ($mb[1] === 'user');
}

function staxx_storage_options(string $disksIni = '/var/local/emhttp/disks.ini',
                               string $sharesDir = '/boot/config/shares'): array {
  $unavailable = [];
  $sections    = staxx_storage_ini_sections($disksIni);
  if ($sections === []) {
    return ['offered' => [], 'unavailable' => [[
      'kind' => 'pool', 'name' => '',
      'reason' => 'Unraid\'s disk state ('.$disksIni.') could not be read, so no location could be checked.',
    ]]];
  }

  $shareName   = basename(rtrim(staxx_appdata_root(), '/'));
  $currentRoot = staxx_stack_root();
  $pools       = [];
  $flash       = null;

  foreach ($sections as $name => $info) {
    $type = trim((string)($info['type'] ?? ''), '"');

    if ($type === 'Flash') {
      // Offered only as where the stacks folder already is — never a flash
      // path invented from nothing, since that would be inventing the one
      // location this whole feature exists to move people away from.
      if ($currentRoot !== '/boot' && strpos($currentRoot, '/boot/') !== 0) continue;
      $err  = '';
      $norm = staxx_settings_validate_path('STACK_ROOT', $currentRoot, $err);
      if ($norm === '') { $unavailable[] = ['kind' => 'flash', 'name' => 'flash', 'reason' => $err]; continue; }
      $removableRaw = $info['removable'] ?? null;
      $flash = [
        'kind' => 'flash', 'name' => 'flash', 'path' => $norm,
        'fsType' => trim((string)($info['fsType'] ?? ''), '"'), 'freeBytes' => null,
        'fsProfile' => '', 'redundant' => false, 'share' => '',
        'removable' => $removableRaw === null ? null : trim((string)$removableRaw, '"') === '1',
      ];
      continue;
    }

    // Neither the array, parity, nor a pool: skip. A pool's own member
    // device shares its type with the pool but reports no fsStatus at all —
    // that is the case most likely to be got wrong, so it is tested for
    // explicitly rather than trusted to fall through by accident.
    if ($type !== 'Cache' || !isset($info['fsStatus'])) continue;

    if (trim((string)$info['fsStatus'], '"') !== 'Mounted') {
      $unavailable[] = ['kind' => 'pool', 'name' => $name,
        'reason' => 'The "'.$name.'" pool is not mounted, so nothing on it could be offered.'];
      continue;
    }

    /* Which shares on this pool may hold the stacks.
     *
     * Every top-level folder on a pool IS a share — Unraid discovers them
     * whether or not anyone made one deliberately — but "any share that stays
     * on this pool" is too wide to be useful advice. Tried that way round
     * first, and on a real box it suggested putting the compose files inside
     * shares called "OBS-Cache", "TEMP" and "docker": somebody else's data,
     * and a suggestion nobody should follow.
     *
     * So two kinds qualify. A share named for this plugin, which is somebody
     * who has deliberately made a home for it and is the case worth
     * encouraging. And the appdata share, the conventional home for a
     * container's data on Unraid, which is where the stacks already sit.
     * Anything else is left to be typed or browsed to by hand, where the
     * person choosing it knows what the share is for and this code does not.
     *
     * Nothing is created here and no share is invented: a folder has to exist
     * already and its own policy has to vouch for staying on this pool. A
     * dot-named folder is skipped because it is not a share at all, so there
     * is no policy to read and nothing to promise about it.
     */
    $profile   = trim((string)($info['fsProfile'] ?? ''), '"');
    $redundant = staxx_storage_redundant($profile);

    /* Why each folder was turned down is kept, not just the fact of it. When
     * nothing on a pool is usable, the specific reason — "the mover would
     * drain this share" — is worth far more than a generic "nothing here",
     * and it is the reason somebody can actually act on. */
    $found  = [];
    $whyNot = [];
    foreach ((array)@scandir('/mnt/'.$name) as $entry) {
      if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
      $folder = '/mnt/'.$name.'/'.$entry;
      if (!is_dir($folder)) continue;
      if (stripos($entry, 'staxx') === false && $entry !== $shareName) continue;
      $shareReason = staxx_storage_share_reason($sharesDir, $entry, $name);
      if ($shareReason !== '') { $whyNot[] = $shareReason; continue; }

      // One level below the folder just proved to exist, never two — the
      // validator refuses a path whose parent does not already exist, so
      // anything nested deeper would be refused on every single install.
      $err  = '';
      $norm = staxx_settings_validate_path('STACK_ROOT', $folder.'/'.STAXX_STACKS_FOLDER, $err);
      if ($norm === '') { $whyNot[] = $err; continue; }

      $found[] = ['share' => $entry, 'path' => $norm];
    }

    if ($found === []) {
      $unavailable[] = ['kind' => 'pool', 'name' => $name,
        'reason' => $whyNot === []
          ? 'The "'.$name.'" pool has no "'.$shareName.'" folder and no share named for StaXX, '
            . 'and making one here would create a share — make it on the Shares page first, set '
            . 'to stay on this pool, and it will appear here.'
          : $whyNot[0]];
      continue;
    }

    /* A folder named for this plugin first, then the appdata share, then the
     * rest by name. Capped, because a pool can easily hold a dozen shares and
     * a wall of them ("system", "logs", "TEMP") buries the one worth taking.
     */
    usort($found, function ($a, $b) use ($shareName) {
      $rank = function (string $s) use ($shareName): int {
        if (stripos($s, 'staxx') !== false) return 0;
        if ($s === $shareName) return 1;
        return 2;
      };
      return [$rank($a['share']), $a['share']] <=> [$rank($b['share']), $b['share']];
    });

    foreach (array_slice($found, 0, 2) as $cand) {
      $pools[] = [
        'kind' => 'pool', 'name' => $name, 'path' => $cand['path'],
        'fsType' => trim((string)($info['fsType'] ?? ''), '"'),
        'freeBytes' => (int)trim((string)($info['fsFree'] ?? '0'), '"') * 1024,
        'fsProfile' => $profile, 'redundant' => $redundant,
        'share' => $cand['share'], 'removable' => null,
      ];
    }
  }

  // Redundant pools first, then whatever order disks.ini gave the rest in —
  // a stable sort, since nothing here claims to rank two equally-redundant
  // pools against each other.
  usort($pools, fn($a, $b) => ($b['redundant'] ? 1 : 0) <=> ($a['redundant'] ? 1 : 0));

  $overlay = null;
  $appdataRoot = rtrim(staxx_appdata_root(), '/');
  $err  = '';
  $norm = staxx_settings_validate_path('STACK_ROOT', $appdataRoot.'/'.STAXX_STACKS_FOLDER, $err);
  if ($norm === '') {
    $unavailable[] = ['kind' => 'overlay', 'name' => $shareName, 'reason' => $err];
  } else {
    $free = @disk_free_space($appdataRoot);
    $overlay = [
      'kind' => 'overlay', 'name' => $shareName, 'path' => $norm,
      'fsType' => 'fuse.shfs', 'freeBytes' => $free !== false ? (int)$free : null,
      // The overlay spans whatever the array's own disks happen to be, which
      // this function has no way to characterise as one pool's redundancy —
      // so, same as an unreported pool profile, it is never claimed.
      'fsProfile' => '', 'redundant' => false, 'share' => $shareName, 'removable' => null,
    ];
  }

  /* An option that is already where the stacks are can never be a
   * destination: staxx_relocate_refuse() rejects it outright. Offering it
   * anyway put a guaranteed refusal in the destination box and pushed a
   * usable pool down into the "or use" list behind it, which is exactly
   * backwards. Flash is exempt — while the stacks are on it, being where they
   * already are is the whole point of the button it draws.
   *
   * Caught by path, plus the share-layer twin of the same folder:
   * /mnt/user/appdata/x and /mnt/<pool>/appdata/x are one directory when that
   * share lives on that pool. realpath() cannot see this, because shfs is a
   * mount point — it resolves /mnt/user/... to itself and never down to the
   * pool behind it, so the tails are compared instead.
   */
  $offered = array_values(array_filter(
    $overlay === null ? $pools : array_merge($pools, [$overlay]),
    fn($o) => !staxx_storage_same_place($o['path'], $currentRoot)
  ));
  if ($flash !== null) $offered[] = $flash;

  return ['offered' => $offered, 'unavailable' => $unavailable];
}
?>
