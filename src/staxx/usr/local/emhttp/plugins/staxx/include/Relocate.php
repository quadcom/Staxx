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
 * drains it onto the array, "prefer" and "only" both pin it to the named
 * pool, and a share with no config file at all, or a config naming a
 * different pool as its cache target, is a policy this cannot vouch for.
 *
 * @return string '' when the share can be trusted to stay on $pool, else why not.
 */
function staxx_storage_share_reason(string $sharesDir, string $shareName, string $pool): string {
  $cfg = @parse_ini_file($sharesDir.'/'.$shareName.'.cfg', false, INI_SCANNER_RAW);
  if ($cfg === false) {
    return 'The "'.$shareName.'" share has no storage policy on record, so whether Unraid\'s mover '
         . 'would carry it off this pool is not known.';
  }

  // An absent key is not the same answer as a key saying "yes", and must not
  // borrow its message: one is a policy this cannot read, the other is a
  // policy that would move the folder. Saying the wrong one of those is the
  // confident-wrong-answer failure this project keeps catching in itself.
  if (!isset($cfg['shareUseCache'])) {
    return 'The "'.$shareName.'" share does not record where it keeps its files, so whether '
         . 'the mover would carry it off this pool is not known.';
  }

  $useCache = trim((string)$cfg['shareUseCache'], '"');
  if ($useCache !== 'prefer' && $useCache !== 'only') {
    return 'The "'.$shareName.'" share is set to move onto the array, so a stack folder there '
         . 'would not reliably stay on this pool.';
  }

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

    $folder = '/mnt/'.$name.'/'.$shareName;
    if (!is_dir($folder)) {
      $unavailable[] = ['kind' => 'pool', 'name' => $name,
        'reason' => 'The "'.$name.'" pool has no "'.$shareName.'" folder yet, and making one here '
                  . 'would create a new share — make the share itself on the Shares page first.'];
      continue;
    }

    $shareReason = staxx_storage_share_reason($sharesDir, $shareName, $name);
    if ($shareReason !== '') {
      $unavailable[] = ['kind' => 'pool', 'name' => $name, 'reason' => $shareReason];
      continue;
    }

    // One level below the folder just proved to exist, never two — the
    // validator below refuses a path whose parent does not already exist, so
    // anything nested deeper would be refused on every single install.
    $err  = '';
    $norm = staxx_settings_validate_path('STACK_ROOT', $folder.'/'.STAXX_STACKS_FOLDER, $err);
    if ($norm === '') { $unavailable[] = ['kind' => 'pool', 'name' => $name, 'reason' => $err]; continue; }

    $profile = trim((string)($info['fsProfile'] ?? ''), '"');
    $pools[] = [
      'kind' => 'pool', 'name' => $name, 'path' => $norm,
      'fsType' => trim((string)($info['fsType'] ?? ''), '"'),
      'freeBytes' => (int)trim((string)($info['fsFree'] ?? '0'), '"') * 1024,
      'fsProfile' => $profile, 'redundant' => staxx_storage_redundant($profile),
      'share' => $shareName, 'removable' => null,
    ];
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

  $offered = $pools;
  if ($overlay !== null) $offered[] = $overlay;
  if ($flash !== null)   $offered[] = $flash;

  return ['offered' => $offered, 'unavailable' => $unavailable];
}
?>
