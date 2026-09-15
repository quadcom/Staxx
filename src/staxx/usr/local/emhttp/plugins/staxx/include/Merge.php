<?PHP
/* StaXX — the server side of merging several stacks into one.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_148 phase 4. The wizard (a later phase, in the browser) is where the
 * merged compose text and the joined .env text actually get BUILT — by
 * javascript/merge-write.js, splicing the chosen stacks' own files together
 * exactly as CLAUDE.md's rule 2 demands, service by service, comments and
 * anchors and all. Nothing here re-does that: this file receives the
 * already-built text and does only what a browser cannot do for itself —
 * touch the filesystem. That is:
 *
 *   - refuse outright, before anything is written, for every reason
 *     PLAN_148's own "Refusals" section lists;
 *   - copy each folded-in stack's companion files into the host's folder,
 *     refusing (never overwriting, never silently renaming) the moment a
 *     name is already in use there;
 *   - write the merged file through staxx_save_stack() — the ordinary save
 *     path, so the ordinary write-back protections apply — and then NAME
 *     the version it captured just before overwriting, so that one entry in
 *     the host's own history is the whole merge: restoring it undoes it;
 *   - carry each arriving container's own image history across, so a
 *     folded-in database can still be rolled back to a build it was proven
 *     on;
 *   - mark each leftover's own record as folded into the host, which is the
 *     one fact its row marker and Remove button (a later phase) draw from —
 *     see staxx_record_merged_into() in Record.php.
 *
 * A merge is not a move: every folded-in stack is left exactly as it was on
 * disk, save for that one additive mark in its own record.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

if (defined('STAXX_MERGE_LOADED')) return;
define('STAXX_MERGE_LOADED', true);

/**
 * Every reason PLAN_148 refuses a merge outright, checked against one
 * already-chosen stack (the host, or one being folded in — the same checks
 * apply to both; see the plan's own "Refusals" section). '' means fine.
 *
 * Deliberately a pure check with no side effect: called once per stack
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

  $pair = staxx_compose_files($file);
  if (count($pair) > 1) {
    return '"'.$rel.'" has a second settings file ('.basename($pair[1]).'), and a stack like that '
         . 'cannot be merged in this version.';
  }

  $body = @file_get_contents($file);
  if ($body === false) return '"'.$rel.'"\'s compose file could not be read.';

  $err = '';
  if (!staxx_validate_compose($body, $err, $dir)) {
    return '"'.$rel.'"\'s compose file could not be read as compose: '.$err;
  }

  return '';
}

/**
 * Every companion-file copy a merge needs to make, and the first name
 * clash found, checked for EVERY folded-in stack before any of them is
 * actually copied — "nothing half-written" means this is a dry run, not
 * the copy itself.
 *
 * ".staxx" (a stack's own history) and ".env" (joined, not copied — see
 * $mergedEnv in staxx_merge_stacks()) are never companion files here.
 *
 * @return array{ok:bool, error:string, copies:array<int,array{from:string,to:string,dir:bool}>}
 */
function staxx_merge_plan_copies(string $hostRel, array $incomingRels): array {
  $hostDir = staxx_stack_dir($hostRel);
  $copies  = [];

  foreach ($incomingRels as $incRel) {
    $incDir = staxx_stack_dir($incRel);
    $err    = '';
    $extras = staxx_stack_extras($incRel, $err);
    if ($extras === null) return ['ok' => false, 'error' => $err, 'copies' => []];

    foreach ($extras as $extra) {
      $name = $extra['name'];
      if ($name === '.staxx' || $name === '.env') continue;

      $dest = $hostDir.'/'.$name;
      if (file_exists($dest) || is_link($dest)) {
        return [
          'ok'    => false,
          'error' => '"'.$hostRel.'" already has a file called "'.$name.'" — folding in "'.$incRel
                   . '" would overwrite it, so the merge was stopped before anything was written. '
                   . 'Rename or move it first.',
          'copies' => [],
        ];
      }

      $copies[] = ['from' => $incDir.'/'.$name, 'to' => $dest, 'dir' => $extra['dir'], 'link' => $extra['link']];
    }
  }

  return ['ok' => true, 'error' => '', 'copies' => $copies];
}

/**
 * Carries one arriving service's image history across from the stack it
 * came from — the whole reason a folded-in database can still be rolled
 * back to a build it was proven on. Best-effort and additive only: it never
 * touches an entry already present under the destination service (there
 * should be none — a merge only ever arrives under a name nothing in the
 * host used before), and is capped at the same retention setting
 * staxx_image_history_push() itself honours.
 */
function staxx_merge_carry_image_history(string $hostRel, string $finalService, array $entries): void {
  if ($entries === [] || $finalService === '') return;

  $dir = staxx_record_dir($hostRel);
  if (!@is_dir($dir) && !@mkdir($dir, 0755, true)) return;

  $record = staxx_record_read($hostRel);
  $images = $record['images'] ?? [];
  if (!empty($images[$finalService])) return;   // never overwrite something already there

  $retain = staxx_update_settings()['retain'];
  $images[$finalService] = array_slice($entries, 0, max(0, $retain));

  staxx_record_write_index($hostRel, [
    'v'        => 1,
    'next'     => $record['next'] ?? 1,
    'versions' => $record['versions'] ?? [],
    'images'   => $images,
  ]);
}

/**
 * The merge itself. $mergedYaml and $mergedEnv (the latter may be null —
 * nothing incoming carried a settings list) are already-built TEXT, from
 * javascript/merge-write.js in the browser; this function only ever writes
 * what it is handed, never composes it. $imageHistoryMap carries each
 * arriving service's final name, keyed the same way the wizard's own
 * findings name a stack and a service:
 *
 *   [ '<incoming stack name>' => [ '<its own service name>' => '<final service name>', ... ], ... ]
 *
 * Optional (pass [] when there is nothing to carry, e.g. no wizard has ever
 * called this yet) — omitting an entry only means that one arriving
 * container starts with no rollback history, never a refusal.
 *
 * @return bool true on success; false leaves every stack exactly as it was
 *   and $error names why.
 */
function staxx_merge_stacks(
  string $hostRel,
  array $incomingRels,
  string $mergedYaml,
  ?string $mergedEnv,
  array $imageHistoryMap,
  string &$error,
  ?array &$facts = null
): bool {
  $error = '';
  $facts = [];

  if (!staxx_store_ready()) {
    $error = 'No data store has been chosen yet.';
    return false;
  }
  if ($incomingRels === []) {
    $error = 'Choose at least one stack to fold in.';
    return false;
  }
  if (in_array($hostRel, $incomingRels, true)) {
    $error = 'A stack cannot be folded into itself.';
    return false;
  }
  if (trim($mergedYaml) === '') {
    $error = 'The merged file arrived empty, so nothing was written.';
    return false;
  }

  // Every refusal PLAN_148 names, gathered for every stack involved — host
  // included, since the plan's own "Refusals" section applies to every
  // chosen stack, not only the ones being folded in — before anything at
  // all is written.
  foreach (array_merge([$hostRel], $incomingRels) as $rel) {
    $why = staxx_merge_check_stack($rel);
    if ($why !== '') { $error = $why; return false; }
  }

  $hostDir = staxx_stack_dir($hostRel);
  $hostReal = (string)@realpath($hostDir);   // staxx_rmtree()'s own containment check needs a real path, not a lexical one
  if (!is_writable($hostDir)) {
    $error = 'The folder for "'.$hostRel.'" cannot be written to.';
    return false;
  }

  // The companion-file copies are worked out and checked for a clash in
  // full before a single byte moves — see staxx_merge_plan_copies()'s own
  // comment.
  $plan = staxx_merge_plan_copies($hostRel, $incomingRels);
  if (!$plan['ok']) { $error = $plan['error']; return false; }

  // Copied BEFORE the host's compose file is touched at all: if a copy
  // fails for a reason the dry run above could not see (the disk fills up
  // mid-merge, say), the host's own file and history are never written to
  // in the first place, and whatever did get copied is removed again.
  $copied = [];
  foreach ($plan['copies'] as $c) {
    // A symlink is recreated as a symlink pointing at the same text, never
    // followed — same reasoning, and the same rule, as
    // staxx_relocate_copy_tree()'s own handling of one; copy() would instead
    // silently follow it and copy whatever it happens to point at.
    if ($c['link']) {
      $target = @readlink($c['from']);
      $ok = $target !== false && @symlink($target, $c['to']);
    } elseif ($c['dir']) {
      $ok = staxx_relocate_copy_tree($c['from'], $c['to'], $error);
    } else {
      $ok = @copy($c['from'], $c['to']);
    }
    if (!$ok) {
      if ($error === '') $error = 'Could not copy "'.basename($c['from']).'" into "'.$hostRel.'".';
      foreach ($copied as $done) {
        if ($done['dir']) staxx_rmtree($done['to'], $hostReal); else @unlink($done['to']);
      }
      return false;
    }
    // Not for a link: staxx_share_perms() resolves realpath() first, which
    // would chase the link and hand permissions to whatever it points at —
    // possibly nothing under this stack at all.
    if (!$c['link']) staxx_share_perms($c['to'], $c['dir']);
    $copied[] = $c;
  }

  // The version about to be captured is exactly the pre-merge file — see
  // staxx_record_capture() in Record.php, which assigns the version it is
  // about to write the record's current "next" number. Naming THAT one
  // (not the "after" capture staxx_save_stack() also makes) is what makes
  // restoring it undo the whole merge in one step.
  $preMergeVersion = staxx_record_read($hostRel)['next'] ?? 1;

  $saveNote = null;
  if (!staxx_save_stack($hostRel, $mergedYaml, $error, $saveNote)) {
    // The host's own file was never touched — undo the companion-file
    // copies so a refused merge truly leaves everything as it was.
    foreach ($copied as $done) {
      if ($done['dir']) staxx_rmtree($done['to'], $hostReal); else @unlink($done['to']);
    }
    return false;
  }

  $title = 'Merged in: '.implode(', ', $incomingRels).' ('.date('Y-m-d').')';
  $nameErr = '';
  if (!staxx_record_name($hostRel, $preMergeVersion, $title, $nameErr)) {
    // Best-effort, same reasoning as every other note in this file: the
    // merge itself already succeeded and must not be undone over a label.
    error_log('StaXX: merge into '.$hostRel.' saved, but its history entry could not be named: '.$nameErr);
  }

  if ($mergedEnv !== null) {
    $envErr = '';
    if (!staxx_write_file($hostRel, '.env', $mergedEnv, true, $envErr)) {
      error_log('StaXX: merge into '.$hostRel.' saved, but the joined .env could not be written: '.$envErr);
    }
  }

  $carried = [];
  foreach ($incomingRels as $incRel) {
    $svcMap = $imageHistoryMap[$incRel] ?? [];
    foreach ($svcMap as $origService => $finalService) {
      $entries = staxx_image_history($incRel, (string)$origService);
      if ($entries === []) continue;
      staxx_merge_carry_image_history($hostRel, (string)$finalService, $entries);
      $carried[] = ['from' => $incRel.'/'.$origService, 'to' => $finalService];
    }
  }

  foreach ($incomingRels as $incRel) {
    if (!staxx_record_mark_merged_into($incRel, $hostRel)) {
      error_log('StaXX: merge into '.$hostRel.' saved, but "'.$incRel.'" could not be marked as folded in.');
    }
  }

  $facts = [
    'note'                => $saveNote ?? '',
    'copiedFiles'         => array_map(fn($c) => basename($c['to']), $copied),
    'imageHistoryCarried' => $carried,
    'preMergeVersion'     => $preMergeVersion,
  ];
  return true;
}
?>
