<?PHP
/* StaXX — the "Scan stored images" window on the Storage tab: finding every
 * Docker image no container uses, sorting it into the groups the window
 * shows, and removing only what was ticked. PLAN_180 Part 1.
 * Copyright 2026, StaXX contributors.
 *
 * This is a second, by-hand door onto the same protection the weekly
 * cleanup already enforces (staxx_update_cleanup() in UpdateRun.php) — never
 * a looser one. Every image this file will ever offer for removal is first
 * checked against staxx_update_keep_digests(), the one definition of "wanted
 * for a rollback", so a digest that function protects is protected here too,
 * by the same key. This file never edits UpdateRun.php's keep-set logic; it
 * only calls it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

if (defined('STAXX_IMAGES_LOADED')) return;
define('STAXX_IMAGES_LOADED', true);

/**
 * Its own resolved php binary, the same three lines Crypt.php carries for
 * the same reason (see staxx_crypt_php_bin()): PHP's environment here is not
 * a login shell, so PATH cannot be trusted, and three duplicated lines are
 * cheaper than a dependency on a file this one has no other reason to need.
 */
function staxx_images_php_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/php', '/usr/local/bin/php'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'php';
}

/**
 * Every digest staxx_update_keep_digests() protects, flattened into one flat
 * set — rule 4a says a roll-back image is matched by digest ALONE, whatever
 * repository key the keep-set happens to file it under, so the repository
 * grouping that function returns is deliberately thrown away here.
 */
function staxx_images_keep_digest_set(): array {
  $flat = [];
  foreach (staxx_update_keep_digests() as $digests) {
    foreach ($digests as $d) $flat[$d] = true;
  }
  return $flat;
}

/**
 * Which stack and service each kept digest belongs to, so the "Kept so
 * ... can be rolled back" row can name it. Walks the same history sources
 * staxx_update_keep_digests() does (staxx_image_history_all() plus the
 * central file's own 'history' half) but keeps the attribution that
 * function's flat return throws away — a second, small walk rather than a
 * change to that safety-critical function. Never touches the "local
 * pointer" half of the keep-set (the currently-pulled image for each known
 * ref): that entry is not a rollback record, and an image still in that
 * role is already excluded here for a simpler reason — a container is using
 * it, or it is the row a stack's own compose file names.
 */
function staxx_images_keep_owners(): array {
  $owners = [];
  $state  = staxx_update_state();

  $historyKeys = array_unique(array_merge(
    array_keys(staxx_image_history_all()),
    array_keys((array)($state['history'] ?? []))
  ));

  $stacksByName = [];
  foreach (staxx_list_stacks() as $s) $stacksByName[$s['name']] = $s;

  foreach ($historyKeys as $key) {
    [$stack, $service] = array_pad(explode('::', $key, 2), 2, '');
    if (!isset($stacksByName[$stack])) continue;
    $label = $stack.' › '.$service;
    foreach (staxx_update_history($stack, $service) as $d) {
      if (!isset($owners[$d])) $owners[$d] = $label;
    }
  }
  return $owners;
}

/**
 * Every stack's own resolved images, by digest where one is already known
 * locally and by reference always — rule 6: "named by a stack" means the
 * value docker compose config actually resolves after interpolation, the
 * same path staxx_update_keep_digests() already trusts for this, never a
 * raw read of the YAML where "${TAG}" would match nothing.
 *
 * @return array{refs: array<string,array{stack:string, running:bool}>}
 *         refs is keyed on the resolved "image:" string.
 */
function staxx_images_stack_refs(): array {
  $refs = [];
  foreach (staxx_list_stacks() as $s) {
    if ($s['file'] === '') continue;
    $meta = staxx_compose_meta($s['file']);
    foreach ($meta['services'] as $service) {
      $ref = trim((string)($service['image'] ?? ''));
      if ($ref === '') continue;
      // First stack found naming a reference wins — good enough for the
      // note's wording, and two stacks sharing one image is not a case this
      // window has to adjudicate between.
      if (!isset($refs[$ref])) $refs[$ref] = ['stack' => $s['name'], 'running' => $s['running']];
    }
  }
  return $refs;
}

/**
 * The grouped list the window shows. Every rule in PLAN_180 lives here:
 *
 *  3.  fails closed the instant a container cannot be read, rather than
 *      quietly omitting it from the in-use set — an omission there would
 *      make that container's image look unused.
 *  4a. a roll-back image is matched by digest alone.
 *  4b. fails closed on the same three conditions staxx_update_cleanup() does.
 *  4c. StaXX's own images (the crypt container) are excluded by their own
 *      label, never by guessing at a name.
 *  6.  a stack's images are its compose file's resolved "image:" values.
 *  7.  "built here" means no RepoDigests (a pulled image always has one).
 *
 * @return array{ok:bool, groups?:array, totals?:array}
 */
function staxx_images_unused(string &$error): array {
  $error = '';

  // 4b, first third: the same "an update is running or queued" guard
  // staxx_update_cleanup() opens with, word for word, because the keep-set
  // below is exactly as stale here as it is there while a pull is underway.
  foreach ((array)(staxx_update_queue_state()['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true)) {
      $error = 'An update is running or queued, so cleanup was skipped. Try again once it finishes.';
      return ['ok' => false];
    }
  }

  // 4b, second third: nothing below can be asked of Docker at all otherwise,
  // and an empty answer would look identical to "nothing is in use".
  if (!staxx_docker_running()) {
    $error = 'The Docker service is not running, so nothing was removed.';
    return ['ok' => false];
  }

  // 4b, last third: without the stacks the keep-set is empty and every
  // roll-back image would read as unused — the same reasoning
  // staxx_update_cleanup() already carries for its own dry run.
  if (!staxx_stacks_visible()) {
    $error = 'StaXX cannot see the stacks right now, so nothing was worked out or removed. '
           . 'Check the array is started, then try again.';
    return ['ok' => false];
  }

  $docker = escapeshellarg(staxx_docker_bin());

  // Rule 3 — every container, read in one batch, not one call each. If the
  // batch as a whole fails, only THEN is each container asked about on its
  // own, purely to name which one Docker could not read for the refusal.
  $psCode = 1;
  $psOut  = staxx_sh(staxx_docker_bin().' ps -aq', 10, $psCode);
  if ($psCode !== 0) {
    $error = 'Docker could not be asked which containers exist, so nothing was worked out.';
    return ['ok' => false];
  }
  $cids = array_values(array_filter(array_map('trim', explode("\n", $psOut))));

  $used = [];   // short image id => true
  if ($cids) {
    $idsArg = implode(' ', array_map('escapeshellarg', $cids));
    $inCode = 1;
    $inOut  = staxx_sh(
      staxx_docker_bin().' inspect --format '.escapeshellarg('{{.Image}}').' '.$idsArg,
      20, $inCode
    );
    $inLines = array_values(array_filter(explode("\n", $inOut), function ($l) { return trim($l) !== ''; }));

    if ($inCode !== 0 || count($inLines) !== count($cids)) {
      // The batch could not account for every container — find the one at
      // fault so the refusal can name it, rather than saying so in general.
      $namesOut = staxx_sh(
        staxx_docker_bin().' ps -a --format '.escapeshellarg('{{.ID}}'."\t".'{{.Names}}'), 10
      );
      $names = [];
      foreach (explode("\n", $namesOut) as $line) {
        $cols = explode("\t", $line, 2);
        if (count($cols) === 2) $names[$cols[0]] = $cols[1];
      }
      foreach ($cids as $cid) {
        $oneCode = 1;
        staxx_sh(staxx_docker_bin().' inspect --format '.escapeshellarg('{{.Image}}').' '.escapeshellarg($cid), 8, $oneCode);
        if ($oneCode !== 0) {
          $error = 'Docker could not read the container "'.($names[$cid] ?? $cid).'" ('.$cid.'), so StaXX '
                 . 'cannot tell which images are in use. Nothing will be removed until that container '
                 . 'is fixed or deleted.';
          return ['ok' => false];
        }
      }
      // Could not reproduce the failure a second time — still refuse, since
      // the count genuinely disagreed once and a stale answer is worse than
      // a repeated check.
      $error = 'Docker could not be asked about every container, so StaXX cannot tell which images '
             . 'are in use. Nothing will be removed — try again in a moment.';
      return ['ok' => false];
    }

    foreach ($inLines as $line) {
      $used[staxx_update_short_id(trim($line))] = true;
    }
  }

  // The candidates: one image per Docker image ID, its tags (if any) kept
  // together on the same row.
  $lsOut = staxx_sh(
    staxx_docker_bin().' images --no-trunc --format '
      .escapeshellarg('{{.ID}}'."\t".'{{.Repository}}'."\t".'{{.Tag}}'),
    20
  );
  // The repository name (everything before the tag) of every image a
  // container is actually using — read from the SAME listing, before it is
  // filtered down to candidates, since a used row is exactly what an
  // "older release of something running" row has to be compared against.
  $usedRepos = [];
  $byId = [];
  foreach (explode("\n", $lsOut) as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 3 || trim($cols[0]) === '') continue;
    $id = trim($cols[0]);
    $hasTag = $cols[1] !== '<none>' && $cols[2] !== '<none>';

    if (isset($used[staxx_update_short_id($id)])) {
      if ($hasTag) $usedRepos[$cols[1]] = true;
      continue; // rule: never an image a container uses
    }
    if (!isset($byId[$id])) $byId[$id] = [];
    if ($hasTag) $byId[$id][] = $cols[1].':'.$cols[2];
  }
  if (!$byId) {
    return ['ok' => true, 'groups' => ['keep' => [], 'dangling' => [], 'older' => [], 'unused' => [], 'wanted' => []],
             'totals' => ['removableCount' => 0, 'removableBytes' => 0, 'keptCount' => 0, 'keptBytes' => 0]];
  }

  // Rules 4a/4c/6/7 all need docker image inspect's own view — RepoDigests,
  // labels and byte size — asked once over every candidate ID, not one call
  // per image.
  $idsArg = implode(' ', array_map('escapeshellarg', array_keys($byId)));
  $inspectOut = staxx_sh(
    staxx_docker_bin().' image inspect --format '.escapeshellarg('{{json .}}').' '.$idsArg, 30
  );

  $keepDigests = staxx_images_keep_digest_set();
  $keepOwners  = staxx_images_keep_owners();
  $stackRefs   = staxx_images_stack_refs();

  $groups = ['keep' => [], 'dangling' => [], 'older' => [], 'unused' => [], 'wanted' => []];
  $removableCount = 0; $removableBytes = 0; $keptCount = 0; $keptBytes = 0;

  foreach (explode("\n", trim($inspectOut)) as $jsonLine) {
    $jsonLine = trim($jsonLine);
    if ($jsonLine === '') continue;
    $info = json_decode($jsonLine, true);
    if (!is_array($info)) continue;

    $id   = (string)($info['Id'] ?? '');
    if ($id === '' || !isset($byId[$id])) continue;
    $tags = $byId[$id];
    $size = (int)($info['Size'] ?? 0);

    // Rule 4c — StaXX's own images (today, only the cryptography
    // container), excluded by the label that container is built with,
    // never by name — see staxx_crypt_images() for the same filter.
    $labels = (array)($info['Config']['Labels'] ?? []);
    if (($labels['staxx.crypt'] ?? '') === '1') continue;

    $digests = [];
    foreach ((array)($info['RepoDigests'] ?? []) as $rd) {
      $at = strrpos($rd, '@');
      if ($at !== false) $digests[] = substr($rd, $at + 1);
    }

    $row = ['id' => $id, 'tags' => $tags, 'size' => $size, 'note' => ''];

    // Rule 4a — roll-back protection, by digest alone.
    $keptDigest = null;
    foreach ($digests as $d) { if (isset($keepDigests[$d])) { $keptDigest = $d; break; } }
    if ($keptDigest !== null) {
      $owner = $keepOwners[$keptDigest] ?? '';
      $row['note'] = $owner !== ''
        ? 'Kept so "'.$owner.'" can be rolled back.'
        : 'Kept for rolling back an update.';
      $groups['keep'][] = $row;
      $keptCount++; $keptBytes += $size;
      continue;
    }

    // Dangling — left behind by an update or a rebuild, no tag at all.
    if (!$tags) {
      $groups['dangling'][] = $row;
      $removableCount++; $removableBytes += $size;
      continue;
    }

    // Rule 6/7 — does a stack still name this exact reference?
    $namedBy = null;
    foreach ($tags as $ref) {
      if (isset($stackRefs[$ref])) { $namedBy = $stackRefs[$ref]; break; }
    }
    // Rule 7 — built here: no RepoDigests at all (a pulled image always has
    // one).
    $builtLocally = !$digests;

    // "Still wanted" images keep their own checkbox — rule 2 lets the
    // server accept their id from the page like any other selectable row —
    // so they count toward what CAN be removed, same as every other
    // checkable group, even though the box starts unticked.
    // A running stack can name an image its container is not on yet — a
    // newer pull waiting for a recreate — so being named at all is enough to
    // leave a row unticked, whether or not the stack is running.
    if ($namedBy !== null) {
      $row['note'] = $namedBy['running']
        ? 'The stack "'.$namedBy['stack'].'" names this, but its container is not using it yet. '
          . 'Removing it means it downloads again the next time the stack is recreated.'
        : 'The stack "'.$namedBy['stack'].'" uses this. It is stopped, so removing this '
          . 'means it downloads again when you start it.';
      $groups['wanted'][] = $row;
      $removableCount++; $removableBytes += $size;
      continue;
    }
    if ($builtLocally) {
      $row['note'] = 'Built on this server. Removing it means building it again; it cannot be downloaded.';
      $groups['wanted'][] = $row;
      $removableCount++; $removableBytes += $size;
      continue;
    }

    // Older release of something still running: this row's own repository
    // (the part of the tag before the colon) is one $usedRepos already
    // named — built above from the SAME `docker images` listing, so a
    // repository whose local name differs from its hub path (the Part 2
    // fault this plan sits beside) is compared under its own name on both
    // sides and still matches correctly.
    $isOlder = false;
    foreach ($tags as $ref) {
      $c = strrpos($ref, ':');
      $repo = $c !== false ? substr($ref, 0, $c) : $ref;
      if (isset($usedRepos[$repo])) { $isOlder = true; break; }
    }

    $row['note'] = $isOlder
      ? 'An older release of an app that is running, older than the ones kept for rolling back.'
      : 'No stack uses this. It will download again if you ever need it.';
    $groups[$isOlder ? 'older' : 'unused'][] = $row;
    $removableCount++; $removableBytes += $size;
  }

  // Largest first within each group.
  foreach ($groups as &$g) {
    usort($g, function ($a, $b) { return $b['size'] <=> $a['size']; });
  }
  unset($g);

  return [
    'ok'     => true,
    'groups' => $groups,
    'totals' => [
      'removableCount' => $removableCount, 'removableBytes' => $removableBytes,
      'keptCount'      => $keptCount,      'keptBytes'      => $keptBytes,
    ],
  ];
}

/**
 * Starts the removal as a detached job, following staxx_crypt_start_job()'s
 * own setsid/log/STAXX_JOB_END shape exactly, so the page's existing `job`
 * poller can follow it unmodified.
 *
 * Rule 2 — the page sends a list of image IDs; this rebuilds the candidate
 * list itself, right now, and silently drops any ID not on it (an ID from a
 * stale window, or a crafted one) rather than trusting the caller. An ID is
 * also checked for shape before anything else touches it.
 */
function staxx_images_remove_job(array $ids, string &$error): string {
  $error = '';

  $listing = staxx_images_unused($error);
  if (!$listing['ok']) return ''; // $error already set, with the right sentence

  $allowed = [];
  foreach (['dangling', 'older', 'unused', 'wanted'] as $g) {
    foreach ($listing['groups'][$g] as $row) $allowed[$row['id']] = $row;
  }

  $rows = [];
  foreach ($ids as $id) {
    $id = (string)$id;
    if (!preg_match('/^sha256:[0-9a-f]{64}$/', $id)) continue;
    if (isset($allowed[$id])) $rows[] = $allowed[$id];
  }
  if (!$rows) {
    $error = 'None of the selected images could be removed — this list may be out of date. '
           . 'Close this window and open it again.';
    return '';
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) { $error = 'Could not create '.STAXX_JOB_DIR; return ''; }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  // Handed to the detached process as plain data via var_export(), the same
  // pattern staxx_crypt_start_job() uses to run one of this plugin's own
  // functions in the background — never shell arguments built from user
  // input, since the ids were already checked against the server's own
  // list above.
  $php = staxx_images_php_bin().' -r '.escapeshellarg(
    'require '.var_export(__DIR__.'/Images.php', true).'; '
    .'exit(staxx_images_do_remove('.var_export($rows, true).') ? 0 : 1);'
  );
  $inner = $php.' 2>&1; echo "'.STAXX_JOB_END.' $?"';

  @file_put_contents($log, '$ removing the selected images'."\n\n");
  @chmod($log, 0600);

  @exec('setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/**
 * The job body: rule 5, one row at a time — every repo:tag first (never
 * `-f`; a multi-tag image refuses on its ID until each tag is gone), then
 * the ID for whatever tags it had none of, or to catch a dangling row that
 * had no tag at all. Echoes progress the way every StaXX job does, and the
 * one summary line the window reads its "freed" total from.
 *
 * Only ever called from the detached process staxx_images_remove_job()
 * starts — never anywhere else in this file — so the rows it is handed have
 * already been checked against the live candidate list at the moment the
 * job was started.
 */
function staxx_images_do_remove(array $rows): bool {
  $docker  = staxx_docker_bin();
  $freed   = 0;
  $removed = 0;
  $ok      = true;

  foreach ($rows as $row) {
    $label = $row['tags'] ? implode(', ', $row['tags']) : $row['id'];
    echo 'Removing '.$label."...\n";

    foreach ($row['tags'] as $tag) {
      $code = 1;
      $out = staxx_sh($docker.' rmi '.escapeshellarg($tag).' 2>&1', 20, $code);
      if ($code !== 0) echo '  '.trim($out)."\n";
    }
    $code = 1;
    $out = staxx_sh($docker.' rmi '.escapeshellarg($row['id']).' 2>&1', 20, $code);

    $stillCode = 1;
    staxx_sh($docker.' image inspect '.escapeshellarg($row['id']).' >/dev/null 2>&1', 8, $stillCode);
    if ($stillCode !== 0) {
      $removed++;
      $freed += (int)$row['size'];
    } else {
      $ok = false;
      $reason = trim($out) !== '' ? trim($out) : 'Docker refused to remove it.';
      echo 'Kept '.$label.' — '.$reason."\n";
    }
  }

  echo "\n".'Removed '.$removed.' image'.($removed === 1 ? '' : 's').' and freed '.staxx_images_human_bytes($freed).".\n";
  return $ok;
}

/** "1.2 GB" / "512 MB" — the same rough shape used throughout the page. */
function staxx_images_human_bytes(int $bytes): string {
  if ($bytes <= 0) return '0 MB';
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $i = 0; $n = (float)$bytes;
  while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
  return ($i >= 2 ? number_format($n, 1) : number_format($n, 0)).' '.$units[$i];
}
