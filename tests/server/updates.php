<?php
/* Detection core for PLAN_45 — the state file, the digest probes, the
 * per-image ask and the scope-to-image collector — checked against the real
 * installed Updates.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/updates.php root@<box>:/tmp/
 *     plink … "php /tmp/updates.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /boot/config/plugins/staxx/updates.json:
 * STAXX_UPDATE_STATE is pointed at a scratch file in /tmp before the
 * include, deleted up front and again on exit (register_shutdown_function,
 * so a fatal error cannot leave it behind).
 *
 * staxx_update_check() only reads the registry and the local image store —
 * it never pulls, builds or starts anything — which is why it is safe to
 * call here, on Adrian's production box, exactly as this test does. Even so,
 * this file never calls any verb that could touch a running container.
 *
 * Cases matter here because they are refusals, not because they are the
 * happy path: a missing or corrupt state file must still hand back sane
 * defaults, a failed registry lookup must read as "could not check" and
 * never as "up to date", two checks must not be allowed to run at once, and
 * a scope with nothing in it must come back empty rather than erroring. */

$scratch = '/tmp/staxx-updates-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
  echo "scratch state removed\n";
});

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

$fails = 0;
$skips = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function skip(string $what, string $reason): void {
  global $skips;
  $skips++;
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

ok('scratch state path is in force', getenv('STAXX_UPDATE_STATE') === $scratch);
ok('the real config file is not the scratch path',
   $scratch !== '/boot/config/plugins/staxx/updates.json');

/* -------------------------------------------------- 1. missing state file */

@unlink($scratch);
$state = staxx_update_state();
ok('missing file: checked is an int',    is_int($state['checked'] ?? null));
ok('missing file: ok is a bool',         is_bool($state['ok'] ?? null));
ok('missing file: error is a string',    is_string($state['error'] ?? null));
ok('missing file: inspector is a string', is_string($state['inspector'] ?? null));
ok('missing file: paused is false, not null', ($state['paused'] ?? null) === false);
ok('missing file: images is an array',   is_array($state['images'] ?? null));
ok('missing file: history is an array',  is_array($state['history'] ?? null));
ok('missing file: bases is an array',    is_array($state['bases'] ?? null));

/* ------------------------------------------------- 2. corrupt state file */

file_put_contents($scratch, 'not json at all');
$state2 = staxx_update_state();
ok('corrupt file: same default shape, not an error',
   is_int($state2['checked']) && is_bool($state2['ok']) && is_string($state2['error'])
   && is_array($state2['images']) && is_array($state2['history']) && is_array($state2['bases']));
ok('corrupt file: paused is false, not null', ($state2['paused'] ?? null) === false);
@unlink($scratch);

/* --------------------------------------------- 3. save round-trips, mode, mtime */

$state = staxx_update_state();
$state['checked'] = 123456;
$state['error']   = 'round-trip marker';
ok('save: reports success', staxx_update_state_save($state) === true);
ok('save: file now exists', is_file($scratch));
$perm = fileperms($scratch) & 0777;
ok('save: file mode is 0600', $perm === 0600, sprintf('0%o', $perm));

$read = staxx_update_state();
ok('save: value round-trips', $read['checked'] === 123456 && $read['error'] === 'round-trip marker');

sleep(1);
$mtimeBefore = filemtime($scratch);
ok('save: identical save leaves mtime unchanged',
   staxx_update_state_save($read) === true && filemtime($scratch) === $mtimeBefore);

$read['checked'] = 999999;
ok('save: a changed save does update mtime',
   staxx_update_state_save($read) === true && filemtime($scratch) > $mtimeBefore);

/* --------------------------------------------------- 4. the inspector probe */

$inspector = staxx_docker_inspector();
ok('inspector: one of the three known words',
   in_array($inspector, ['imagetools', 'manifest', 'hub'], true), $inspector);
$stateAfterProbe = staxx_update_state();
ok('inspector: recorded in the state file and matches what was returned',
   ($stateAfterProbe['inspector'] ?? '') !== '' && $stateAfterProbe['inspector'] === $inspector,
   $stateAfterProbe['inspector'] ?? '');

/* --------------------------------------------- 5/6. failure is not "current" */

$noSuchImage = 'staxx-no-such-image-'.getmypid().'/nope:latest';

$remote = staxx_image_remote($noSuchImage);
ok('remote: a failed lookup returns [], never a fabricated "up to date"', $remote === []);

$local = staxx_image_local($noSuchImage);
ok('local: an image nobody has returns []', $local === []);

/* ------------------------------------------------- 7. scope-to-image collection */

$all = staxx_update_images('all');
ok('images(all): returns an array', is_array($all));
$keysOk = true;
$valsOk = true;
foreach ($all as $image => $refs) {
  if (!is_string($image) || $image === '') { $keysOk = false; break; }
  if (!is_array($refs) || count($refs) === 0) { $valsOk = false; break; }
  foreach ($refs as $r) {
    if (!is_string($r) || strpos($r, '::') === false) { $valsOk = false; break 2; }
  }
}
ok('images(all): every key is a non-empty image reference', $keysOk);
ok('images(all): every value is a non-empty list of "path::service" strings', $valsOk);

ok('images: a scope matching no stack returns []',
   staxx_update_images('staxx-no-such-stack') === []);

$stacks = function_exists('staxx_list_stacks') ? staxx_list_stacks() : [];
$anyStack = null;
foreach ($stacks as $s) { if (($s['parses'] ?? false)) { $anyStack = $s; break; } }

if ($anyStack === null) {
  skip('images: one real stack is a subset of all', 'no parsing stack found on this box');
} else {
  $scoped = staxx_update_images($anyStack['name']);
  $subset = true;
  foreach ($scoped as $image => $refs) {
    if (!array_key_exists($image, $all)) { $subset = false; break; }
    if (array_diff($refs, $all[$image])) { $subset = false; break; }
  }
  ok('images: scoping to one real stack is a subset of images(all)', $subset, $anyStack['name']);
}

/* --------------------------------------------- 8. start refuses a bad scope */

foreach (['../etc', 'a b', ''] as $badScope) {
  $err = '';
  $job = staxx_update_check_start($badScope, false, $err);
  ok('check_start refuses scope '.var_export($badScope, true),
     $job === '' && $err !== '', $err);
}

/* --------------------------------------------------------- 9. the lock */

$dir  = STAXX_UPDATE_DIR;
$lock = $dir.'/lock';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
@rmdir($lock);
$madeLock = mkdir($lock);
ok('lock: created by hand', $madeLock);

$result = staxx_update_check('all', false);
ok('lock: a second pass refuses while one is held',
   ($result['ok'] ?? true) === false && ($result['error'] ?? '') !== '' && ($result['asked'] ?? -1) === 0,
   json_encode($result));

@rmdir($lock);
ok('lock: removed afterwards', !is_dir($lock));

/* ----------------------------------------------- 10. a skip is honoured */

if ($anyStack === null) {
  skip('skip: recently-asked image is not re-asked', 'no parsing stack found on this box');
} else {
  $scopeName = $anyStack['name'];
  $scopedImages = staxx_update_images($scopeName);
  if (empty($scopedImages)) {
    skip('skip: recently-asked image is not re-asked', 'stack "'.$scopeName.'" uses no images');
  } else {
    $imgRef = array_key_first($scopedImages);

    $state = staxx_update_state();
    $state['images'][$imgRef] = ['asked' => time()];
    staxx_update_state_save($state);

    $checked = staxx_update_check($scopeName, false);
    ok('skip: an image asked within the TTL is counted as skipped, not re-asked',
       is_array($checked) && ($checked['skipped'] ?? 0) >= 1, json_encode($checked));

    $state = staxx_update_state();
    $state['images'][$imgRef] = ['asked' => time()];
    staxx_update_state_save($state);

    $forced = staxx_update_check($scopeName, true);
    ok('skip: force ignores the recent-ask memory and asks anyway',
       is_array($forced) && ($forced['skipped'] ?? 1) === 0, json_encode($forced));
  }
}

/* ------------------------------------------- 11. the pill, PLAN_45 phase 3 */

$neverAsked = staxx_updates_pill_for_image('staxx-never-asked/nope:latest', []);
ok('pill: no entry at all reports unknown, never current',
   $neverAsked['state'] === 'unknown', $neverAsked['state']);
ok('pill: unknown label says "never checked"', $neverAsked['label'] === 'never checked');

/* A stack where one service has an update and another is fine — the same
 * fold staxx_updates_for_row() runs over a compose file's services, just fed
 * two hand-built pills instead of a real stack. */
$updatePill  = ['state' => 'update', 'label' => '1.0 → 1.1', 'source' => '', 'tip' => 't'];
$currentPill = ['state' => 'current', 'label' => 'up to date', 'source' => '', 'tip' => 't'];
$mixed = staxx_updates_aggregate([$updatePill, $currentPill]);
ok('aggregate: one updatable service among fine ones reports "update"',
   $mixed['state'] === 'update', json_encode($mixed));
ok('aggregate: count is 1, not the total number of services', $mixed['count'] === 1, (string)$mixed['count']);

/* A stack where one service could not be checked must never read as fully
 * up to date — that is the failure this whole feature exists to avoid. */
$errorPill = ['state' => 'error', 'label' => 'could not check', 'source' => '', 'tip' => 't'];
$partialFailure = staxx_updates_aggregate([$currentPill, $errorPill]);
ok('aggregate: an unchecked service beats "up to date"',
   $partialFailure['state'] !== 'current', $partialFailure['state']);
ok('aggregate: label is not "up to date" when something failed to check',
   $partialFailure['label'] !== 'up to date', $partialFailure['label']);

/* --------------------------------------------------- 12. update-skip refusals */

$state = staxx_update_state();
$state['images'] = [];
staxx_update_state_save($state);

$err = '';
ok('skip: refuses an image with no state-file entry at all',
   staxx_update_skip('staxx-not-tracked/nope:latest', $err) === false && $err !== '', $err);

$state = staxx_update_state();
$state['images']['staxx-test/no-remote:latest'] = ['local' => 'sha256:aaa'];
staxx_update_state_save($state);

$err = '';
ok('skip: refuses an image with no remote digest recorded',
   staxx_update_skip('staxx-test/no-remote:latest', $err) === false && $err !== '', $err);

/* -------------------------------------------- 13. skip, then a newer digest */

$image = 'staxx-test/skip-cycle:latest';
$state = staxx_update_state();
$state['images'][$image] = [
  'local' => 'sha256:aaa', 'remote' => 'sha256:bbb', 'was' => '1.0', 'version' => '1.1',
];
staxx_update_state_save($state);

$before = staxx_updates_pill_for_image($image, staxx_update_state()['images']);
ok('skip cycle: before skipping, an update is reported', $before['state'] === 'update', $before['state']);

$err = '';
ok('skip: succeeds against a tracked image with a remote digest',
   staxx_update_skip($image, $err) === true, $err);

$afterSkip = staxx_updates_pill_for_image($image, staxx_update_state()['images']);
ok('skip cycle: after skipping this version, it reports current',
   $afterSkip['state'] === 'current', $afterSkip['state']);

// A newer digest replaces the one that was skipped — the dismissal must not
// silence a real update that shows up afterwards.
$state = staxx_update_state();
$state['images'][$image]['remote']  = 'sha256:ccc';
$state['images'][$image]['version'] = '1.2';
staxx_update_state_save($state);

$afterNewer = staxx_updates_pill_for_image($image, staxx_update_state()['images']);
ok('skip cycle: a newer remote digest reports "update" again',
   $afterNewer['state'] === 'update', $afterNewer['state']);

/* ------------------------------------------------- 14. tag suggestions */

$suggest = staxx_tag_suggestions('latest', ['stable', 'canary', '0.9', '0.30', 'nightly']);
ok('suggest: never offers a test build', !in_array('canary', $suggest, true) && !in_array('nightly', $suggest, true), json_encode($suggest));
ok('suggest: a rolling tag outranks a higher version number', ($suggest[0] ?? '') === 'stable', json_encode($suggest));

$versionOnly = staxx_tag_suggestions('latest', ['0.9', '0.30', '0.29.1']);
ok('suggest: 0.30 outranks 0.9 numerically, not lexically', ($versionOnly[0] ?? '') === '0.30', json_encode($versionOnly));

$rcExcluded = staxx_tag_suggestions('latest', ['0.30.0-rc.1', '0.29']);
ok('suggest: an rc build is never suggested', !in_array('0.30.0-rc.1', $rcExcluded, true), json_encode($rcExcluded));

$noMissing = staxx_tag_suggestions('latest', ['latest', 'stable']);
ok('suggest: the withdrawn tag itself is never offered back', !in_array('latest', $noMissing, true), json_encode($noMissing));

ok('suggest: empty tag list suggests nothing', staxx_tag_suggestions('latest', []) === []);

/* ---------------------------------------------- 15. tagmissing classification */

// staxx_remote_failure_reason() only takes the tag-list detour when handed an
// image — the two-argument form (used by every other 'notfound' caller in
// this codebase before this feature existed) must behave exactly as before.
$plain = staxx_remote_failure_reason('Error: manifest unknown');
ok('reason: notfound with no image given stays notfound (old two-arg behaviour)', $plain === 'notfound', $plain);

// A repository this box can genuinely resolve tags for, on the live server,
// is needed to exercise the real tagmissing path — skip cleanly where there
// isn't one, since this is a server test with no fixture registry to hit.
$knownRepo = 'library/alpine'; // Hub's own base image; always has many tags
$aliveTags = staxx_registry_tags($knownRepo);
if ($aliveTags === []) {
  skip('reason: tags returned, ours absent -> tagmissing', 'could not reach the registry from this box');
  skip('reason: tags returned, ours present -> stays notfound', 'could not reach the registry from this box');
} else {
  $missingTag = 'staxx-definitely-not-a-real-tag-'.getmypid();
  $tagsOut = null;
  $reason = staxx_remote_failure_reason('Error: manifest unknown', $knownRepo.':'.$missingTag, $tagsOut);
  ok('reason: tags returned, ours absent -> tagmissing', $reason === 'tagmissing', $reason);
  ok('reason: tagmissing hands back the tag list it fetched', is_array($tagsOut) && count($tagsOut) > 0);

  $realTag = $aliveTags[0];
  $tagsOut2 = null;
  $reason2 = staxx_remote_failure_reason('Error: manifest unknown', $knownRepo.':'.$realTag, $tagsOut2);
  ok('reason: tags returned, ours present -> stays notfound, no guess invented',
     $reason2 === 'notfound', $reason2);
}

// A repository that cannot be resolved at all (bad host) must stay notfound —
// a failed tag lookup is never itself evidence of a withdrawn tag.
$deadHost = 'staxx-no-such-registry-'.getmypid().'.invalid/some/repo:latest';
$tagsOut3 = null;
$reason3 = staxx_remote_failure_reason('Error: manifest unknown', $deadHost, $tagsOut3);
ok('reason: a tag list that cannot be fetched at all stays notfound', $reason3 === 'notfound', $reason3);
ok('reason: no tags handed back when the lookup itself failed', $tagsOut3 === null);

/* ------------------------------------------- 16. the tagmissing pill, never "could not check" */

// The fixture's "tags" is built the same way staxx_update_check() must build
// it: run the registry's raw list (including a test build) through
// staxx_tag_suggestions() and store only what comes back — never the raw
// list itself. That is the contract this whole case exists to pin down.
$rawTags   = ['canary', 'stable', '0.30', '0.30.0-rc.2', '0.29'];
$shortlist = staxx_tag_suggestions('latest', $rawTags);

$state = staxx_update_state();
$state['images']['staxx-test/withdrawn:latest'] = [
  'error' => 'tag withdrawn', 'suggest' => $shortlist[0] ?? '', 'tags' => $shortlist,
];
staxx_update_state_save($state);

$pill = staxx_updates_pill_for_image('staxx-test/withdrawn:latest', staxx_update_state()['images']);
ok('pill: a withdrawn tag reports state "tagmissing", not "error"', $pill['state'] === 'tagmissing', $pill['state']);
ok('pill: label is "tag withdrawn"', $pill['label'] === 'tag withdrawn', $pill['label']);
ok('pill: never falls into the "could not check" catch-all', $pill['label'] !== 'could not check', $pill['label']);
ok('pill: the tip names what is offered instead', strpos($pill['tip'], 'stable') !== false, $pill['tip']);
ok('pill: suggest is read back as a string, never an array',
   is_string($pill['suggest'] ?? null), gettype($pill['suggest'] ?? null));
ok('pill: suggest equals the first entry of the stored tags shortlist',
   ($pill['suggest'] ?? null) === $state['images']['staxx-test/withdrawn:latest']['tags'][0]);
ok('pill: the stored tags shortlist is at most three long',
   count($state['images']['staxx-test/withdrawn:latest']['tags']) <= 3);
ok('pill: no test-build tag anywhere in the stored shortlist',
   !in_array('canary', $state['images']['staxx-test/withdrawn:latest']['tags'], true)
   && !in_array('0.30.0-rc.2', $state['images']['staxx-test/withdrawn:latest']['tags'], true),
   json_encode($state['images']['staxx-test/withdrawn:latest']['tags']));

// The aggregate fold must not let a withdrawn tag disappear behind "up to
// date" either — the same invariant case 11 already checks for "error".
$tagmissingPill = ['state' => 'tagmissing', 'label' => 'tag withdrawn', 'source' => '', 'tip' => 't'];
$foldedWithCurrent = staxx_updates_aggregate([$currentPill, $tagmissingPill]);
ok('aggregate: a withdrawn tag beats "up to date" in the fold',
   $foldedWithCurrent['state'] !== 'current', $foldedWithCurrent['state']);

/* --------------------------------------- 17. the 'asked' stamp on permanent failures */

// Rather than fabricating a withdrawn-tag image, discover a real one: the
// live state file already holds every image staxx_update_check() has ever
// classified, so read it directly and read-only — never through
// STAXX_UPDATE_STATE, which this test has pointed at scratch, and never
// written back to — and walk it for one whose recorded state is currently
// 'tagmissing'. Not every server will have hit one, hence the SKIPs below.
$realRaw    = @file_get_contents(STAXX_CFG_DIR.'/updates.json');
$realData   = $realRaw !== false ? json_decode($realRaw, true) : null;
$realImages = is_array($realData) && is_array($realData['images'] ?? null) ? $realData['images'] : [];

$withdrawnRef   = null;
$withdrawnEntry = null;
foreach ($realImages as $ref => $entry) {
  if (!is_array($entry)) continue;
  if (staxx_updates_pill_for_image($ref, $realImages)['state'] === 'tagmissing') {
    $withdrawnRef = $ref;
    $withdrawnEntry = $entry;
    break;
  }
}

if ($withdrawnRef === null) {
  skip('asked stamp: a withdrawn-tag image has its asked stamp set and a usable suggestion',
       'no image on this box is currently classified tagmissing');
  skip('asked stamp: a tagmissing image is not re-asked within six hours',
       'no image on this box is currently classified tagmissing');
} else {
  ok('asked stamp: the withdrawn-tag image has an asked timestamp',
     isset($withdrawnEntry['asked']) && is_int($withdrawnEntry['asked']) && $withdrawnEntry['asked'] > 0,
     $withdrawnRef);
  ok('asked stamp: its suggest is a non-empty string present in its tags',
     is_string($withdrawnEntry['suggest'] ?? null) && $withdrawnEntry['suggest'] !== ''
     && in_array($withdrawnEntry['suggest'], (array)($withdrawnEntry['tags'] ?? []), true),
     json_encode($withdrawnEntry));

  // Seed the scratch state with the same entry, freshly asked, and run a
  // real pass across everything on the box — the six-hour memory must skip
  // it rather than asking the registry again, and its recorded reason must
  // stay exactly what it was, proving it was skipped and not re-classified.
  $state = staxx_update_state();
  $state['images'] = [$withdrawnRef => array_merge($withdrawnEntry, ['asked' => time()])];
  staxx_update_state_save($state);

  $checked = staxx_update_check('all', false);
  ok('asked stamp: a tagmissing image is not re-asked within six hours',
     is_array($checked) && ($checked['skipped'] ?? 0) >= 1, json_encode($checked));

  $after = staxx_update_state()['images'][$withdrawnRef] ?? [];
  ok('asked stamp: it is still recorded as "tag withdrawn" after the skip',
     ($after['error'] ?? '') === 'tag withdrawn', json_encode($after));
}

/* -------------------------------------------------- 18. the cost guard */

// staxx_registry_tags() must never be called for an image that answered
// normally — it is one extra network request per notfound failure, and the
// whole point of A2 is that a healthy image costs nothing extra. There is no
// seam to inject a call counter into staxx_remote_failure_reason() from here,
// so this instead proves the same thing the function's own contract promises:
// passing an $out string that is NOT a "not found" style failure never
// reaches the tag-list branch at all, regardless of what image is given —
// exercised against a $tags output that would otherwise get filled.
$noLookupTags = null;
$noLookupReason = staxx_remote_failure_reason('Error: connection reset', $knownRepo, $noLookupTags);
ok('cost guard: a non-notfound failure never asks for tags at all',
   $noLookupReason === 'failed' && $noLookupTags === null, $noLookupReason);

/* --------------------------------------- 19. a withdrawn tag is not a failure */

// Reuses whichever real, single-image stack the box already has (same search
// as case 10) — probed first with the exact same real registry call
// staxx_update_check() itself makes, purely to know which of the two
// contracts below currently applies. Never fabricates a stack of its own.
$singleImageStack = null;
$singleImageRef   = '';
foreach ($stacks as $s) {
  if (!($s['parses'] ?? false)) continue;
  $imgs = staxx_update_images($s['name']);
  if (count($imgs) === 1) { $singleImageStack = $s; $singleImageRef = array_key_first($imgs); break; }
}

if ($singleImageStack === null) {
  skip('check: a withdrawn-tag-only pass reports ok true with its own count', 'no single-image stack found on this box');
  skip('check: a pass with a genuine failure still reports ok false, naming the image', 'no single-image stack found on this box');
} else {
  $probeWhy  = '';
  $probeTags = null;
  staxx_image_remote($singleImageRef, $probeWhy, $probeTags);

  if ($probeWhy === 'tagmissing') {
    $checked = staxx_update_check($singleImageStack['name'], true);
    ok('check: a withdrawn-tag-only pass reports ok true',
       $checked['ok'] === true, json_encode($checked));
    ok('check: a withdrawn-tag-only pass counts 0 failed',
       $checked['failed'] === 0, json_encode($checked));
    ok('check: a withdrawn-tag-only pass counts its own tagmissing separately',
       $checked['tagmissing'] === 1, json_encode($checked));
    ok('check: a withdrawn-tag-only pass reports an empty error sentence',
       $checked['error'] === '', $checked['error']);

    $entry = staxx_update_state()['images'][$singleImageRef] ?? [];
    ok('check: the stored suggest is a string, never an array',
       is_string($entry['suggest'] ?? null), gettype($entry['suggest'] ?? null));
    ok('check: the stored suggest equals the first entry of the stored tags',
       ($entry['suggest'] ?? null) === ($entry['tags'][0] ?? null), json_encode($entry));
  } else {
    skip('check: a withdrawn-tag-only pass reports ok true with its own count',
         'this box\'s single-image stack is not currently tagmissing (why: '.$probeWhy.')');
  }

  if ($probeWhy !== '' && $probeWhy !== 'tagmissing' && $probeWhy !== 'limited') {
    $checked2 = staxx_update_check($singleImageStack['name'], true);
    ok('check: a pass with a genuine failure still reports ok false',
       $checked2['ok'] === false, json_encode($checked2));
    ok('check: a genuine failure names the image in the error sentence',
       strpos($checked2['error'], $singleImageRef) !== false, $checked2['error']);
  } else {
    skip('check: a pass with a genuine failure still reports ok false, naming the image',
         'this box\'s single-image stack is not currently a genuine failure (why: '.$probeWhy.')');
  }
}

printf("\n%s — %d failure%s, %d skipped\n",
       $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's', $skips);
exit($fails ? 1 : 0);
