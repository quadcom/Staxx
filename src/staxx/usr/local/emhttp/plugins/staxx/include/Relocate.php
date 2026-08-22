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
 * The shape is fixed and does not change per stack: copy everything, verify
 * everything against what actually landed on disk, switch the stacks folder
 * setting — the point after which the move cannot be undone by discarding
 * the copy — then remove the old folders. Never a rename: across two
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
function staxx_relocate_refuse(string $destInput, string &$error): string {
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
function staxx_relocate_cleanup(string $dest): void {
  $real = @realpath($dest);
  if ($real !== false) staxx_rmtree($real, $real);
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

  $log('Copying every stack to the new location.');
  if (!staxx_relocate_copy_tree($source, $dest, $error)) {
    $log('The copy failed: '.$error);
    staxx_relocate_cleanup($dest);
    $log('The partial copy has been removed. Nothing at the current location was touched.');
    return false;
  }

  $log('Checking every file matches, byte for byte.');
  $problems = staxx_relocate_verify($manifest, $dest);
  if ($problems) {
    foreach ($problems as $p) $log('Verification problem: '.$p);
    staxx_relocate_cleanup($dest);
    $error = 'Verification found '.count($problems).' problem(s) with the copy; see the lines above. '
           . 'The current stacks folder was left untouched and the partial copy was removed.';
    $log($error);
    return false;
  }
  $log('Every file verified — the same files, the same sizes, the same content.');

  $log('Switching the stacks folder setting to the new location.');
  $saveError = '';
  if (!staxx_settings_save(['STACK_ROOT' => $dest], $saveError)) {
    staxx_relocate_cleanup($dest);
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
?>
