<?PHP
/* StaXX — the server side of merging several stacks into one.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_155 rebuilt this file to a different model than PLAN_148 shipped.
 * A merge no longer folds arriving stacks into a host that keeps running —
 * it writes a brand new THIRD stack from text the browser already built
 * (javascript/merge-write.js, splicing the chosen stacks' own files together
 * service by service, comments and anchors and all — CLAUDE.md's rule 2),
 * and retires every source rather than leaving one of them running under a
 * different name. Nothing here composes the merged text: this file only
 * ever does what a browser cannot do for itself — touch the filesystem.
 * That is:
 *
 *   - list a source stack's own companion files (staxx_merge_files()), for
 *     the wizard to offer as things worth carrying into the new stack;
 *   - refuse the whole merge outright, before anything is written, for
 *     every reason the contract in PLAN_155's "Build order" section names;
 *   - take every source down (synchronously — the merge itself is a
 *     short-lived HTTP request, not a background job) before a single file
 *     is copied, so nothing is copied out from under a container still
 *     writing to it — but ONLY when the person asked for that (the $stop
 *     flag, PLAN_155 "Step 6 as settled"); with it off the copy runs
 *     against whatever is on disk right now and the person is trusted to
 *     have stopped things themselves. This is `down --remove-orphans`, not
 *     `stop` (PLAN_155 C14): a stopped container keeps its name, its
 *     networks and its anonymous volumes, so a source carrying
 *     `container_name:` — common in files converted from Unraid templates —
 *     cannot start beside a merely stopped original once the new stack
 *     tries to. Named volumes survive `down` without `-v`, which is what
 *     keeps the repointed storage safe;
 *   - copy exactly the companion files the wizard asked for into the new
 *     stack's own, brand new folder, preserving a source file's mode
 *     wherever it is tighter than the share default, and recreating an
 *     inside-pointing symlink verbatim;
 *   - write the merged file through staxx_save_stack() as a NEW stack —
 *     its own history starts with this entry, nothing is carried across;
 *   - retire each source last: rewrite its own compose file with the text
 *     the browser already built (the "retired" profile lines and a dated
 *     comment), drop a NEEDS-REVIEW.md explaining what happened, and mark
 *     its record as retired into the new stack — see
 *     staxx_record_mark_merged_into() in Record.php.
 *   - start the new stack, once everything above has written and retired
 *     cleanly, when the person asked for that too (the $start flag) — the
 *     same 'up' job the row's own Start button runs, not anything special
 *     to a merge.
 *
 * Retiring is the LAST step precisely because it is destructive to what a
 * source stack could otherwise still do: every failure before it leaves
 * both originals running exactly as they were, with nothing written into
 * the new folder either. A failure that arrives AFTER the sources were
 * taken down brings them back up again before returning, for the same
 * reason (PLAN_155 C11) — a refusal that leaves the originals down is not
 * a refusal, it is a half-done merge.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
// The write phase's copy loop reaches into Relocate.php for a folder entry's
// own copy helper (staxx_relocate_copy_tree()) rather than duplicating it.
require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';

if (defined('STAXX_MERGE_LOADED')) return;
define('STAXX_MERGE_LOADED', true);

/**
 * Every reason a stack cannot take part in a merge at all, checked against
 * one already-chosen stack — every source, since under the new model there
 * is no host to single out. '' means fine.
 *
 * Deliberately a pure check with no side effect: called once per source
 * before anything at all is written, so every refusal can be gathered and
 * reported together rather than stopping at the first one found.
 */
function staxx_merge_check_stack(string $rel): string {
  if (!staxx_valid_path($rel)) return 'Invalid stack name.';

  $dir = staxx_stack_dir($rel);
  if (!is_dir($dir)) return 'There is no stack called "'.$rel.'".';

  $file = staxx_find_compose_file($dir);
  if ($file === '') return '"'.$rel.'" has no compose file, so it cannot take part in a merge.';

  if (staxx_review_locked($rel)) {
    return '"'.$rel.'" is still waiting to be reviewed after an import, so it cannot take part '
         . 'in a merge yet.';
  }

  // A paired override is no longer refused (PLAN_155 C3): the browser reads
  // and applies it itself, and staxx_merge_files() already excludes the
  // pair from the companion-file list via the same staxx_compose_files()
  // rule this used to enforce here.

  $body = @file_get_contents($file);
  if ($body === false) return '"'.$rel.'"\'s compose file could not be read.';

  $err = '';
  if (!staxx_validate_compose($body, $err, $dir)) {
    return '"'.$rel.'"\'s compose file could not be read as compose: '.$err;
  }

  return '';
}

/**
 * A key- or certificate-shaped name — a private key, a signed certificate,
 * or anything sitting in a folder plainly meant to hold one. Used to warn
 * the wizard that a copy of a secret will exist in two places once a merge
 * completes (PLAN_155, "Secrets are duplicated").
 */
function staxx_merge_keylike(string $path, string $name): bool {
  if (preg_match('/\.(pem|key|crt|p12|pfx)$/i', $name)) return true;
  if (stripos($name, 'id_rsa') === 0) return true;
  return (bool)preg_match('#(^|/)(secrets|certs)/#i', $path);
}

/**
 * Every folder a compose file's own volumes: bind source, build: context,
 * env_file: or a configs:/secrets: "file:" source names — so a file sitting
 * UNDER one of them (PLAN_156 F12: "./certs/ca.pem" under a mounted
 * "./certs") reads as referenced too, not only a path spelled out in full.
 * env_file/file: name one FILE rather than a folder, so it is that file's
 * own containing folder that goes on the list — the file itself is already
 * caught by the plain substring check staxx_merge_files() still runs first.
 * A light regex scan, the same shape as staxx_compose_names_file() in
 * Stacks.php, not a second compose parser: a bind source or a build context
 * is always a path (never a bare word — that is a named volume, which this
 * deliberately excludes), so there is nothing here a structural parse would
 * answer better.
 */
function staxx_merge_mounted_folders(string $composeText): array {
  if ($composeText === '') return [];

  $isPathLike = function (string $p): bool {
    return (bool)preg_match('#^(\.{1,2}/|/|~/)#', trim($p));
  };
  $norm = function (string $p): string {
    $p = trim($p, "\"' \t");
    $p = preg_replace('#^\./#', '', $p);
    return rtrim($p, '/');
  };

  $out   = [];
  $lines = preg_split('/\r\n|\r|\n/', $composeText);
  foreach ($lines as $i => $line) {
    // volumes: short form ("- ./name:/target") — a bare "- name:/target"
    // with no leading "./", "../", "/" or "~/" is a named volume, excluded
    // by $isPathLike. build: shorthand ("build: ./name") is a path too.
    if (preg_match('#^\s*-\s*["\']?([^"\':]+)["\']?:#', $line, $m) && $isPathLike($m[1])) {
      $out[] = $norm($m[1]);
    }
    // volumes: long form's "source:", and build:'s own "context:" line.
    if (preg_match('~^\s*(?:source|build|context):\s*["\']?([^"\'#]+?)["\']?\s*$~', $line, $m) && $isPathLike($m[1])) {
      $out[] = $norm($m[1]);
    }
    // env_file:, inline scalar/list on one line or a multi-line list below it.
    if (preg_match('#^\s*env_file:\s*\[?\s*["\']?([^"\'\]]+?)["\']?\s*\]?\s*$#i', $line, $m)) {
      $out[] = $norm(dirname(trim($m[1])));
    } elseif (preg_match('/^\s*env_file:\s*$/i', $line)) {
      for ($j = $i + 1; $j < count($lines); $j++) {
        if (!preg_match('/^\s*-\s*["\']?([^"\'\s][^"\']*)["\']?\s*$/', $lines[$j], $m2)) break;
        $out[] = $norm(dirname(trim($m2[1])));
      }
    }
    // A configs:/secrets: entry's own "file:" source.
    if (preg_match('#^\s*file:\s*["\']?([^"\']+)["\']?\s*$#i', $line, $m)) {
      $out[] = $norm(dirname(trim($m[1])));
    }
  }

  return array_values(array_unique(array_filter($out, function ($p) { return $p !== '' && $p !== '.'; })));
}

/** Is $path itself, or something nested under, one of $folders? */
function staxx_merge_path_under(string $path, array $folders): bool {
  foreach ($folders as $f) {
    if ($path === $f || strpos($path, $f.'/') === 0) return true;
  }
  return false;
}

/**
 * Every file and folder inside a stack's own folder that a merge might
 * carry across, nested — not just the one level staxx_stack_extras() lists
 * for the removal confirmation, because a merge has to reason about a path
 * buried inside a bind-mounted folder too (does it move, is it referenced,
 * is it dangerously large).
 *
 * Excluded outright, and never offered: the paired compose file(s) — the
 * same pairing rule staxx_stack_extras() itself relies on
 * (staxx_compose_files()) — plus .env (joined by its own step, never
 * copied file-for-file), NEEDS-REVIEW.md and HANDOVER.md (neither can
 * exist on a mergeable source today, but excluding them by name costs
 * nothing and closes the door for good), and everything under .staxx/
 * except an image file sitting directly inside it — the icon a merge needs
 * to carry across (PLAN_155, "Icons are already broken").
 *
 * Walking stops the moment the running total for the CURRENT top-level
 * entry passes 10MB — "large" names the first folder that does, and
 * nothing beyond it is measured, because the number stops mattering once
 * the line is crossed (PLAN_155, "the guard").
 *
 * Symlinks are never followed while walking: a linked directory is listed
 * as a link, not descended into. A relative link whose target resolves
 * outside this stack's own folder is listed with 'outside' => true, so the
 * wizard can refuse to carry it rather than recreate a link that points at
 * nothing, or at something else entirely, once it lives somewhere new.
 *
 * @return array{ok:bool, files:array<int,array{path:string,size:int,mode:string,
 *   dir:bool,link:bool,target:string,outside:bool,keyLike:bool,referenced:bool}>,
 *   large:?array{path:string}, override:?string}|null null only for a bad or
 *   missing stack; $error set in that case. 'override' is the paired
 *   override file's own basename (PLAN_155 C3), or null when this source has
 *   none — the browser reads it separately with 'read' and applies it
 *   itself, so it is never one of the 'files' offered as a companion.
 */
function staxx_merge_files(string $rel, string &$error): ?array {
  $error = '';
  if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return null; }

  $dir = staxx_stack_dir($rel);
  if (!is_dir($dir)) { $error = 'There is no stack called "'.$rel.'".'; return null; }

  $root = @realpath($dir);
  if ($root === false) { $error = 'There is no stack called "'.$rel.'".'; return null; }

  $mainFile     = staxx_find_compose_file($dir);
  $composeNames = array_map('basename', staxx_compose_files($mainFile));
  $composeText  = '';
  foreach (staxx_compose_files($mainFile) as $f) {
    $composeText .= "\n" . (string)@file_get_contents($f);
  }
  $mountedFolders = staxx_merge_mounted_folders($composeText);   // PLAN_156 F12

  $imageExts = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];

  $files      = [];
  $large      = null;
  // One running total per top-level entry, keyed by its own path, so a big
  // folder stops the walk without a big FILE elsewhere in the tree being
  // blamed for it.
  $totals     = [];

  // Breadth doesn't matter here, only that every folder's own children are
  // listed together — a plain queue of "subfolders still to walk" does that
  // without recursion, and lets the walk simply stop dead the moment $large
  // is set.
  $queue = [['sub' => '', 'top' => '']];
  while ($queue !== [] && $large === null) {
    $item = array_shift($queue);
    $sub  = $item['sub'];
    $top  = $item['top'];   // the top-level entry this path's size counts against
    $full = $sub === '' ? $dir : $dir.'/'.$sub;

    $entries = @scandir($full);
    if ($entries === false) continue;
    natcasesort($entries);

    foreach ($entries as $name) {
      if ($name === '.' || $name === '..') continue;
      $path = $sub === '' ? $name : $sub.'/'.$name;
      $isTop = $sub === '';

      if ($isTop) {
        if (in_array($name, $composeNames, true)) continue;
        if ($name === '.env') continue;
        if (strcasecmp($name, STAXX_REVIEW_FILE) === 0) continue;
        if (strcasecmp($name, STAXX_HANDOVER_FILE) === 0) continue;

        // .staxx itself is never OFFERED — a caller choosing it as a "path"
        // would otherwise copy the whole record folder wholesale, versions
        // and all, defeating the very exclusion the next block enforces.
        // It IS descended into, so the loop below can pick out an image
        // file sitting directly inside it.
        if ($name === '.staxx' && is_dir($dir.'/'.$path) && !is_link($dir.'/'.$path)) {
          $queue[] = ['sub' => $path, 'top' => $path];
          continue;
        }
      }

      // Inside .staxx/, only an image file sitting directly in it survives —
      // nothing below THAT is ever walked, since .staxx holds no folders a
      // merge has any business reading.
      if ($sub === '.staxx') {
        $full2 = $dir.'/'.$path;
        $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (is_link($full2) || !is_file($full2) || !in_array($ext, $imageExts, true)) continue;
      }

      $full2  = $dir.'/'.$path;
      $isLink = is_link($full2);
      $isDir  = !$isLink && is_dir($full2);
      $st     = @lstat($full2) ?: [];
      $size   = $isDir ? 0 : (int)($st['size'] ?? 0);
      $mode   = isset($st['mode']) ? substr(sprintf('%o', $st['mode']), -4) : '';

      $target  = '';
      $outside = false;
      if ($isLink) {
        $target = (string)@readlink($full2);
        // A relative target resolves against the folder the LINK sits in —
        // the same arithmetic a shell `cd` there would do, not against the
        // stack's own root.
        $resolved = ($target !== '' && $target[0] === '/')
          ? $target
          : @realpath(dirname($full2).'/'.$target);
        if ($resolved === false || ($resolved !== $root && strpos($resolved, $root.'/') !== 0)) {
          $outside = true;
        }
      }

      $files[] = [
        'path'       => $path,
        'size'       => $size,
        'mode'       => $mode,
        'dir'        => $isDir,
        'link'       => $isLink,
        'target'     => $target,
        'outside'    => $outside,
        'keyLike'    => staxx_merge_keylike($path, $name),
        // PLAN_156 F12: named outright, OR sitting under a folder the
        // compose file mounts, builds from, or loads settings/a secret from —
        // "Not used by anything" is now only genuinely loose files.
        'referenced' => ($composeText !== '' && strpos($composeText, $path) !== false)
          || staxx_merge_path_under($path, $mountedFolders),
      ];

      if ($isDir) {
        $childTop = $isTop ? $path : $top;
        $queue[]  = ['sub' => $path, 'top' => $childTop];
      } else {
        $countTop = $isTop ? $path : $top;
        $totals[$countTop] = ($totals[$countTop] ?? 0) + $size;
        if ($totals[$countTop] > 10 * 1024 * 1024) {
          $large = ['path' => $countTop];
          break;
        }
      }
    }
  }

  return ['ok' => true, 'files' => $files, 'large' => $large, 'override' => $composeNames[1] ?? null];
}

/**
 * Is $to a safe relative destination inside the new stack's folder? Shares
 * staxx_valid_filename()'s character set and its ".." and "/" refusals,
 * segment by segment, so an intermediate folder name is held to exactly the
 * same rule as a leaf filename — including staxx_valid_filename()'s own
 * refusal of a bare compose filename, which already covers three of the
 * four names staxx_merge_dangerous_names() exists to add the rest of.
 */
function staxx_merge_safe_to(string $to): bool {
  if ($to === '' || strlen($to) > 255) return false;
  if ($to[0] === '/') return false;
  foreach (explode('/', $to) as $part) {
    if (!staxx_valid_filename($part)) return false;
  }
  return true;
}

/**
 * Every basename Compose would ever read as a main compose file or as an
 * override. Any of the four override names is live wherever it sits
 * (PLAN_155 C5, measured against Compose directly) — there is no such thing
 * as one of these names sitting inert — so a copied-in file under one of
 * them must never land beside a main file it would silently start pairing
 * with. Checked against every name in the list rather than just the new
 * stack's own chosen filename, because the new stack's compose file has not
 * been written yet when this is checked and staxx_save_stack() may keep an
 * existing name it does not control here — refusing the whole family is
 * simpler than predicting which one applies, and no legitimate companion
 * file is named any of these.
 */
function staxx_merge_dangerous_names(): array {
  return array_merge(STAXX_COMPOSE_FILENAMES, [
    'compose.override.yaml', 'compose.override.yml',
    'docker-compose.override.yaml', 'docker-compose.override.yml',
  ]);
}

/**
 * Starts every stack named, in the same shape staxx_merge_stacks() uses to
 * take them down — `compose … up -d`, not `start` (PLAN_155 C14): the down
 * step this undoes removed the containers outright, so there is nothing
 * left to wake, only to recreate from the same files and config. Used only
 * to undo a down this same call made, when a later step then fails
 * (PLAN_155 C11): a merge that takes the originals down and then refuses
 * must not leave them down, or the refusal is a worse outcome than not
 * merging at all.
 *
 * @param array<int,string> $rels the sources THIS call took down, in the
 *   order they were taken down — an empty list (nothing was taken down,
 *   because $stop was never set) returns '' straight away, and never
 *   touches $cmd.
 * @return string '' when there was nothing to restart; otherwise a
 *   sentence to append to the refusal already being returned — the plan's
 *   own wording when every restart succeeded, or which one did not so a
 *   person knows which stack still needs starting by hand.
 */
function staxx_merge_restart_sources(array $rels, string $cmd): string {
  if ($rels === []) return '';
  $failed = [];
  foreach ($rels as $rel) {
    $srcDir   = staxx_stack_dir($rel);
    $srcFiles = staxx_compose_files(staxx_find_compose_file($srcDir));
    $startCmd = 'cd '.escapeshellarg($srcDir).' && '.$cmd.' '.staxx_compose_file_args($srcFiles)
              . ' up -d 2>&1';
    $code = 1;
    staxx_sh($startCmd, 120, $code);
    if ($code !== 0) $failed[] = $rel;
  }
  if ($failed === []) return ' The originals were started again.';
  return ' The originals were started again, except "'.implode('", "', $failed).'", which did not '
       . 'restart on its own — start it by hand.';
}

/**
 * The merge itself — see PLAN_155's "Build order and the browser-to-server
 * contract" for the exact field shapes and the order enforced below. Every
 * step before "copy files" is a pure check: nothing on disk changes until
 * every refusal above it has already passed.
 *
 * $body and $retired[...] are already-built TEXT from javascript/merge-
 * write.js in the browser; this function only ever writes what it is
 * handed, never composes it.
 *
 * Order of operations (PLAN_155 C11): every pure check runs first —
 * validation, the fingerprint match, and a full walk of the requested
 * copies, resolving every symlink's target and refusing one that resolves
 * outside its own source stack by name — before anything with a side
 * effect happens. Only then are the sources taken down (when $stop is
 * set), only then are files copied, and only then is anything written or
 * retired. A failure from the copy step onward undoes the new folder AND
 * brings back up whatever this call took down, so the ordering is not just
 * safer to reason about, it is what makes that guarantee possible.
 *
 * $stop and $start are the two switches PLAN_155's "Step 6 as settled"
 * puts at the foot of the consequences column, both off by default in the
 * wizard. $stop alone means "take every source down, then write and
 * retire, but leave the new stack for the person to start themselves";
 * $start also brings the new stack up once everything else has succeeded. $start
 * without $stop is refused outright — the browser already locks the pair
 * together in its own UI, so a request arriving with $start set and $stop
 * clear can only be a bug or a bypass, never a considered choice, and
 * silently forcing $stop on would run a step nobody asked for.
 *
 * @param array<string,string> $fingerprints source rel => staxx_stack_fingerprint()
 * @param array<int,array{from:string,path:string,to:?string}> $files
 * @param array<string,string> $retired source rel => its rewritten compose text
 * @param array<string,string> $retiredOverride source rel => its rewritten
 *   OVERRIDE text (PLAN_155 C3), for the sources that have one; written over
 *   the paired override file in the same retiring step and with the same
 *   best-effort failure handling as $retired — a source with no entry here
 *   simply has no override to rewrite, not a failure.
 * @return bool true on success; false leaves every source running exactly
 *   as it was — down only for the instant it took to fail, then brought
 *   back up again — and $error names why, with *The originals were started again.*
 *   appended when a restart was actually needed. $facts carries
 *   ['conflict' => true] on a fingerprint mismatch, else on success
 *   ['name'=>…, 'created'=>[…]] plus ['job'=>…] when $start actually
 *   launched the new stack's 'up' job — absent, not false or null, when
 *   $start was not set, or was set but the job itself could not be
 *   started (logged, not reported as a merge failure: everything the merge
 *   itself promises has already happened by that point).
 */
function staxx_merge_stacks(
  string $newRel,
  array $sourceRels,
  array $fingerprints,
  string $body,
  ?string $env,
  array $files,
  array $retired,
  bool $stop,
  bool $start,
  string &$error,
  ?array &$facts = null,
  array $retiredOverride = []
): bool {
  $error = '';
  $facts = [];

  if (!staxx_store_ready()) {
    $error = 'No data store has been chosen yet.';
    return false;
  }
  if (trim($body) === '') {
    $error = 'The merged file arrived empty, so nothing was written.';
    return false;
  }
  if (!staxx_valid_path($newRel)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, must start '
           . 'with a letter or number, and must be 63 characters or fewer.';
    return false;
  }
  if (is_dir(staxx_stack_dir($newRel))) {
    $error = 'There is already a stack (or a folder) called "'.$newRel.'". Pick another name or '
           . 'another folder.';
    return false;
  }
  if ($start && !$stop) {
    $error = 'Starting the new stack means stopping the originals first — send "stop" along with "start".';
    return false;
  }

  if (count($sourceRels) < 2) {
    $error = 'Choose at least two stacks to merge.';
    return false;
  }
  if (count($sourceRels) !== count(array_unique($sourceRels))) {
    $error = 'The same stack was named twice in this merge.';
    return false;
  }
  foreach ($sourceRels as $rel) {
    if (!staxx_valid_path($rel)) { $error = 'Invalid stack name.'; return false; }
    if ($rel === $newRel) {
      $error = 'The new stack cannot share a name with one of the stacks being merged.';
      return false;
    }
  }

  // Every reason a source cannot take part in a merge at all — see
  // staxx_merge_check_stack()'s own comment.
  foreach ($sourceRels as $rel) {
    $why = staxx_merge_check_stack($rel);
    if ($why !== '') { $error = $why; return false; }
  }

  // Every source's fingerprint has to match what the wizard actually read —
  // a stack changed on the server between opening the wizard and pressing
  // Merge means the merged text was built from bytes that are no longer
  // true. Reported as a conflict, not a plain refusal, so the browser can
  // say so rather than just failing.
  foreach ($sourceRels as $rel) {
    $now  = staxx_stack_fingerprint($rel);
    $want = (string)($fingerprints[$rel] ?? '');
    if ($want === '' || $now !== $want) {
      $error = '"'.$rel.'" has changed on the server since the merge wizard read it, so nothing '
             . 'was written. Close the wizard and start the merge again.';
      $facts = ['conflict' => true];
      return false;
    }
  }

  // Every copy the wizard has asked for, checked against that source's OWN
  // staxx_merge_files() listing — a dry run, not the copy itself, so a
  // problem here is found before a single byte moves.
  $dangerous = staxx_merge_dangerous_names();
  $listings  = [];
  $seenTo    = [];
  $copies    = [];
  // Every path the plan names for a given source, whether it is copied
  // (with its own "to") or left behind ("to": null) — a folder entry must
  // not carry a child that the plan named separately either way, or a
  // "leave it behind" choice inside a copied folder is silently ignored
  // (PLAN_155 C13).
  $namedPaths = [];

  foreach ($files as $f) {
    if (!is_array($f)) { $error = 'The file list was malformed.'; return false; }
    $from = (string)($f['from'] ?? '');
    $path = (string)($f['path'] ?? '');
    $to   = array_key_exists('to', $f) ? $f['to'] : null;

    if (!in_array($from, $sourceRels, true)) {
      $error = '"'.$from.'" is not one of the stacks being merged.';
      return false;
    }
    if (!isset($listings[$from])) {
      $listErr = '';
      $listing = staxx_merge_files($from, $listErr);
      if ($listing === null) { $error = $listErr; return false; }
      $byPath = [];
      foreach ($listing['files'] as $entry) $byPath[$entry['path']] = $entry;
      $listings[$from] = $byPath;
    }
    if (!isset($listings[$from][$path])) {
      $error = '"'.$path.'" is not a file "'.$from.'" can offer to this merge.';
      return false;
    }

    $namedPaths[] = ['from' => $from, 'path' => $path];
    if ($to === null) continue;   // left behind, on purpose
    if (!is_string($to) || !staxx_merge_safe_to($to)) {
      $error = '"'.$to.'" is not a safe place to put a copied file.';
      return false;
    }

    $entry = $listings[$from][$path];
    if ($entry['link'] && $entry['outside']) {
      $error = '"'.$path.'" in "'.$from.'" is a link pointing outside its own stack folder, so it '
             . 'cannot be carried into a new one.';
      return false;
    }
    if (in_array(strtolower(basename($to)), $dangerous, true)) {
      $error = '"'.$to.'" would be read as a compose file of its own, so it cannot be used as a '
             . 'destination name. Choose another name for it.';
      return false;
    }
    if (isset($seenTo[$to])) {
      $error = 'Two files would both be copied to "'.$to.'" in the new stack.';
      return false;
    }
    $seenTo[$to] = true;
    $copies[]    = ['from' => $from, 'path' => $path, 'to' => $to, 'entry' => $entry];
  }

  // Every source is taken down BEFORE a single file moves — copying a
  // folder a container is still writing to gives a torn copy (PLAN_155,
  // "Copying a running stack gives a torn copy"). Synchronous and short:
  // staxx_sh()'s own timeout, not the background job runner, because this
  // whole request has to answer before the browser moves on to writing the
  // new stack.
  //
  // Only run at all when $stop is set — with it off, the person has said
  // the originals should keep running for now, and the copy proceeds
  // against whatever is on disk at this moment on their own say-so.
  //
  // `down --remove-orphans`, not `stop` (PLAN_155 C14): a stopped container
  // keeps its name, its networks and its anonymous volumes, and the new
  // stack cannot start beside one still holding them — a source carrying
  // `container_name:` (common in files converted from Unraid templates)
  // would then fail outright with a name conflict. Never `-v`: named
  // volumes must survive so the repointed storage stays intact. `--profile
  // '*'` activates every profile so a profiled service goes too — without
  // it `down` cannot even see a service behind an inactive profile.
  //
  // $stoppedRels tracks exactly which sources THIS call took down, so any
  // failure from here on — including one further source in this same loop
  // refusing to go down — can bring the others straight back up rather
  // than leaving them down for no reason anyone asked for (PLAN_155 C11).
  $cmd         = '';
  $stoppedRels = [];
  if ($stop) {
    $cmd = staxx_compose_cmd();
    if ($cmd === '') { $error = 'Compose is not installed, so nothing can be run.'; return false; }

    foreach ($sourceRels as $rel) {
      $srcDir   = staxx_stack_dir($rel);
      $srcFiles = staxx_compose_files(staxx_find_compose_file($srcDir));
      $stopCmd  = 'cd '.escapeshellarg($srcDir).' && '.$cmd.' '.staxx_compose_file_args($srcFiles)
                . " --profile '*' down --remove-orphans 2>&1";
      $code = 1;
      $out  = staxx_sh($stopCmd, 120, $code);
      if ($code !== 0) {
        $error = 'Could not stop "'.$rel.'" before merging it, so nothing was written: '.trim($out);
        $error .= staxx_merge_restart_sources($stoppedRels, $cmd);
        return false;
      }
      $stoppedRels[] = $rel;
    }
  }

  // Disk state changes from here on. Every failure below undoes exactly
  // what THIS call created — the new folder is removed — AND brings back
  // up whatever this call took down, so a refused merge leaves both
  // sources running exactly as they were before Merge was pressed, not
  // down and abandoned (PLAN_155 C11).
  $restartNote = function () use ($stoppedRels, $cmd) {
    return staxx_merge_restart_sources($stoppedRels, $cmd);
  };

  $folder     = staxx_path_folder($newRel);
  $madeFolder = false;
  if ($folder !== '') {
    $folderDir = staxx_stack_root().'/'.$folder;
    if (!is_dir($folderDir)) {
      if (!@mkdir($folderDir, 0755, true)) {
        $error = 'Could not create the folder "'.$folder.'".';
        $error .= $restartNote();
        return false;
      }
      $madeFolder = true;
    }
  }

  $newDir = staxx_stack_dir($newRel);
  if (!is_dir($newDir) && !@mkdir($newDir, 0755, true)) {
    $error = 'Could not create "'.$newRel.'".';
    if ($madeFolder) @rmdir(staxx_stack_root().'/'.$folder);
    $error .= $restartNote();
    return false;
  }
  $newReal = (string)@realpath($newDir);

  $undo = function () use ($newDir, $newReal, $madeFolder, $folder) {
    staxx_rmtree($newDir, $newReal);
    if ($madeFolder) @rmdir(staxx_stack_root().'/'.$folder);
  };

  $createdPaths = [];
  foreach ($copies as $c) {
    $from  = staxx_stack_dir($c['from']).'/'.$c['path'];
    $to    = $newDir.'/'.$c['to'];
    // Made from $to's own path, not from whether the entry is a link — a
    // symlink's destination folder (e.g. "conf/" for "conf/current.conf")
    // needs to exist just as much as a plain file's does, and this runs
    // before either branch below.
    $toDir = dirname($to);
    if (!is_dir($toDir) && !@mkdir($toDir, 0755, true)) {
      $error = 'Could not create a folder to hold "'.$c['to'].'".';
      $undo();
      $error .= $restartNote();
      return false;
    }

    $entry = $c['entry'];
    if ($entry['link']) {
      // Recreated as a symlink pointing at the same text, never followed —
      // the same rule staxx_relocate_copy_tree() itself follows for a link
      // found mid-tree. A link resolving outside its source stack was
      // already refused above, before anything was stopped, so getting
      // here means the target is safe to recreate verbatim.
      $target = @readlink($from);
      // Belt and braces: the browser is now expected to send files only,
      // never a folder AND one of its own children (PLAN_155 C13), but a
      // symlink already recreated by an earlier folder copy in this same
      // plan counts as done rather than a failure — the two entries agree
      // on the same target, so recreating it a second time is not needed.
      $already = $target !== false && is_link($to) && @readlink($to) === $target;
      $ok = $already || ($target !== false && @symlink($target, $to));
    } elseif ($entry['dir']) {
      // A folder entry copies only the children that have no entry of
      // their own in this same plan — a plan naming both a folder and one
      // renamed or left-behind child inside it must not have the folder
      // copy silently carry that child under its old name too
      // (PLAN_155 C13; the browser is not expected to send such a plan any
      // more, but the server no longer trusts that on faith).
      $prefix  = $c['path'].'/';
      $exclude = [];
      foreach ($namedPaths as $other) {
        if ($other['from'] === $c['from'] && $other['path'] !== $c['path']
            && strpos($other['path'], $prefix) === 0) {
          $exclude[] = substr($other['path'], strlen($prefix));
        }
      }
      $treeProgress = ['done' => 0, 'total' => 0, 'pct' => -1];
      $ok = staxx_relocate_copy_tree($from, $to, $error, null, '', $treeProgress, $exclude);
    } else {
      $ok = @copy($from, $to);
    }
    if (!$ok) {
      if ($error === '') $error = 'Could not copy "'.$c['path'].'" from "'.$c['from'].'".';
      $undo();
      $error .= $restartNote();
      return false;
    }

    if (!$entry['link']) {
      // staxx_share_perms() sets the ordinary share mode; a source mode that
      // is TIGHTER than that must survive it, or a private key copied from
      // 0600 comes out world-readable the moment the store sits under
      // /mnt/ (PLAN_155, "Secrets are duplicated").
      $srcMode = @fileperms($from);
      staxx_share_perms($to, $entry['dir']);
      if ($srcMode !== false && !$entry['dir']) {
        // "Tighter" is a subset of permission bits, not a smaller number:
        // 0700 is larger than 0644 yet grants the world nothing. The
        // intersection is never looser than either side.
        $srcBits = $srcMode & 0777;
        $curBits = (@fileperms($to) ?: 0666) & 0777;
        if (($srcBits & $curBits) !== $curBits) @chmod($to, $srcBits & $curBits);
      }
    }

    $createdPaths[] = $c['to'];
  }

  $saveErr = '';
  if (!staxx_save_stack($newRel, $body, $saveErr)) {
    $error = $saveErr;
    $undo();
    $error .= $restartNote();
    return false;
  }
  array_unshift($createdPaths, basename(staxx_find_compose_file($newDir)));

  if ($env !== null) {
    $envErr = '';
    if (!staxx_write_file($newRel, '.env', $env, true, $envErr)) {
      // Best-effort, same reasoning as every other note in this file: the
      // new stack already exists and must not be undone over its settings
      // file failing to write.
      error_log('StaXX: merge wrote "'.$newRel.'", but the joined .env could not be written: '.$envErr);
    } else {
      $createdPaths[] = '.env';
    }
  }

  // Retiring is LAST, and best-effort per source: the new stack already
  // exists by this point, so a failure retiring one source is logged rather
  // than treated as undoing everything already written.
  $date = date('Y-m-d');
  foreach ($sourceRels as $rel) {
    $text = (string)($retired[$rel] ?? '');
    if ($text === '') {
      error_log('StaXX: merge into "'.$newRel.'" wrote, but "'.$rel.'" had no retirement text and '
               . 'was left running as it stood.');
      continue;
    }
    // The override's basename is read from what pairs with the main file
    // right now, before staxx_save_stack() below rewrites that file's
    // CONTENT — its name and extension, which is all pairing looks at,
    // never change, so the order between this and the retire below does
    // not matter, but reading it here keeps both writes in one place.
    $overrideBasename = staxx_compose_files(staxx_find_compose_file(staxx_stack_dir($rel)))[1] ?? '';

    $retireErr = '';
    if (!staxx_save_stack($rel, $text, $retireErr)) {
      error_log('StaXX: merge into "'.$newRel.'" wrote, but "'.$rel.'" could not be retired: '.$retireErr);
      continue;
    }

    $overrideText = (string)($retiredOverride[$rel] ?? '');
    if ($overrideBasename !== '' && $overrideText !== '') {
      $overrideErr = '';
      if (!staxx_write_file($rel, basename($overrideBasename), $overrideText, true, $overrideErr)) {
        error_log('StaXX: merge into "'.$newRel.'" retired "'.$rel.'", but its override could not '
                 . 'be rewritten: '.$overrideErr);
      }
    }

    $notice = 'Retired into '.$newRel.' on '.$date.'. This stack was joined into that one and '
            . 'cannot be started. Remove it from its row once the new stack is confirmed working, '
            . 'or delete this file and the "retired" profile lines to bring it back.';
    if (@file_put_contents(staxx_stack_dir($rel).'/'.STAXX_REVIEW_FILE, $notice) === false) {
      error_log('StaXX: merge into "'.$newRel.'" retired "'.$rel.'", but its NEEDS-REVIEW.md '
               . 'could not be written.');
    }
    if (!staxx_record_mark_merged_into($rel, $newRel)) {
      error_log('StaXX: merge into "'.$newRel.'" retired "'.$rel.'", but its record could not be '
               . 'marked.');
    }
  }

  $facts = ['name' => $newRel, 'created' => $createdPaths];

  // Starting is the very last thing a merge does, and only when asked —
  // the same 'up' job the row's own Start button runs, so a merge that
  // starts its new stack behaves exactly like starting it by hand a moment
  // later would have. Best-effort like retiring above: the merge itself has
  // already fully succeeded by this point (everything is written and every
  // source retired), so a job that fails to launch is logged, not turned
  // into a merge failure — 'job' is simply absent from $facts and the new
  // stack sits there unstarted, exactly as it would if $start had been off.
  if ($start) {
    $jobErr = '';
    $job = staxx_start_job($newRel, 'up', $jobErr);
    if ($job !== '') {
      $facts['job'] = $job;
    } else {
      error_log('StaXX: merge into "'.$newRel.'" wrote and retired every source, but starting it '
               . 'failed: '.$jobErr);
    }
  }

  return true;
}
?>
