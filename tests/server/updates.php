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

printf("\n%s — %d failure%s, %d skipped\n",
       $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's', $skips);
exit($fails ? 1 : 0);
