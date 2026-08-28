<?php
/* PLAN_68 Part B, piece 3 — what locations the stacks folder could move to,
 * checked against the real installed Relocate.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/storage.php root@<box>:/tmp/
 *     plink … "php /tmp/storage.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches /boot/config/shares or the real STACK_ROOT/ARCHIVE_ROOT
 * setting — every fixture disks.ini and share .cfg lives under /tmp, passed
 * straight to staxx_storage_options()'s own $disksIni/$sharesDir parameters,
 * the same reasoning tests/server/watch.php gives for
 * staxx_watch_template_claims()'s directory argument. The one thing that
 * does have to be real is where each fixture pool's "existing top-level
 * folder" lives, because staxx_storage_options() calls is_dir() and the real
 * STACK_ROOT path validator on it — a memory-filesystem path would be
 * (correctly) refused, and /tmp is exactly that. So each fixture pool's
 * folder is a fresh, uniquely-named scratch directory nested INSIDE the
 * real appdata share (/mnt/user/appdata/staxx-storage-test/...), never a
 * new top-level entry under /mnt itself — the same share-safety this file's
 * own subject matter is about. Nothing here is a real pool and nothing here
 * is offered to a live install; it only proves the function's own logic.
 * Removed on every exit path, including a fatal error.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$scratchDir = '/tmp/staxx-storage-test';
$poolRoot   = '/mnt/user/appdata/staxx-storage-test';

register_shutdown_function(function () use ($scratchDir, $poolRoot) {
  @exec('rm -rf '.escapeshellarg($scratchDir));
  @exec('rm -rf '.escapeshellarg($poolRoot));
  echo "scratch files and folders removed\n";
});

@exec('rm -rf '.escapeshellarg($scratchDir));
@exec('rm -rf '.escapeshellarg($poolRoot));
mkdir($scratchDir, 0755, true);
mkdir($poolRoot, 0755, true);

/** Write a disks.ini-shaped fixture: $sections is name => [key => value]. */
function storage_test_write_ini(string $path, array $sections): void {
  $out = '';
  foreach ($sections as $name => $fields) {
    $out .= '["'.$name."\"]\n";
    foreach ($fields as $k => $v) $out .= $k.'="'.$v."\"\n";
  }
  file_put_contents($path, $out);
}

/** Write a share's own .cfg fixture (no sections, one file per share). */
function storage_test_write_share(string $dir, string $shareName, array $fields): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $out = '';
  foreach ($fields as $k => $v) $out .= $k.'="'.$v."\"\n";
  file_put_contents($dir.'/'.$shareName.'.cfg', $out);
}

// The one folder name every pool candidate is checked against is whatever
// the real box's appdata setting names — read it exactly the way
// staxx_storage_options() itself does, so the fixture folders below line up
// with what the function under test will actually go looking for.
$shareName = basename(rtrim(staxx_appdata_root(), '/'));

/* --------------------------- 1. the real disks.ini, read-only ------------ */

$real = staxx_storage_options();
ok('the real disks.ini is read without a fatal error',
   is_array($real) && isset($real['offered']) && isset($real['unavailable']));

$allNames = array_merge(
  array_column($real['offered'], 'name'),
  array_column($real['unavailable'], 'name')
);
ok('no pool\'s own member device is ever named as a candidate in its own right',
   !in_array('m2cache2', $allNames, true), implode(',', $allNames));

$flashEntries = array_values(array_filter(
  array_merge($real['offered'], $real['unavailable']),
  fn($e) => $e['kind'] === 'flash'
));
/* Flash is only ever mentioned while the stacks are ON it — offered as "where
 * they already are", never as a path invented from nothing, since flash is the
 * one location this whole feature exists to move people away from. So the
 * assertion has to depend on where the stacks currently sit: exactly one
 * mention when they are on flash, none at all when they are not. Written as
 * "exactly one, always" when this suite was new, which was true then only
 * because the store had not been moved off flash yet — a stale assumption,
 * not a bug in the code it was testing. */
$onFlash = staxx_stack_root() === '/boot' || strpos(staxx_stack_root(), '/boot/') === 0;
ok($onFlash
     ? 'the flash drive is reported, because the stacks are on it'
     : 'the flash drive is not mentioned at all, because the stacks are not on it',
   count($flashEntries) === ($onFlash ? 1 : 0),
   json_encode($flashEntries));
if (count($flashEntries) === 1 && isset($flashEntries[0]['removable'])) {
  ok('a genuinely removable boot device is reported as such',
     $flashEntries[0]['removable'] === true, json_encode($flashEntries[0]));
}

/* ------------------------------------- 2. a pool that is not mounted ----- */

$iniB = $scratchDir.'/unmounted.ini';
storage_test_write_ini($iniB, ['storagetest-unmounted' => [
  'type' => 'Cache', 'fsType' => 'zfs', 'fsStatus' => '', 'fsProfile' => 'mirror',
  'fsSize' => '1000', 'fsFree' => '1000',
]]);
$r = staxx_storage_options($iniB, $scratchDir);
ok('an unmounted pool is never offered', !in_array('storagetest-unmounted', array_column($r['offered'], 'name'), true));
$u = current(array_filter($r['unavailable'], fn($e) => $e['name'] === 'storagetest-unmounted')) ?: [];
ok('...and is refused with mounting as the reason',
   ($u['name'] ?? '') === 'storagetest-unmounted' && stripos($u['reason'] ?? '', 'mounted') !== false,
   json_encode($u));

/* ---------------------- 3. a member device, not a pool in its own right -- */

$iniC = $scratchDir.'/member.ini';
storage_test_write_ini($iniC, ['storagetest-member' => [
  'type' => 'Cache', 'name' => 'storagetest-member',
  // No fsStatus and no fsProfile at all — a real pool's member device, per
  // the real disks.ini shape this fixture is copied from.
]]);
$r = staxx_storage_options($iniC, $scratchDir);
ok('a member device is never offered as a pool',
   !in_array('storagetest-member', array_column($r['offered'], 'name'), true));
ok('...and is not even reported as an unavailable one — it is not a candidate at all',
   !in_array('storagetest-member', array_column($r['unavailable'], 'name'), true));

/* --- 4. two mounted, properly-shared pools: ordering, profile, KiB->bytes - */

mkdir($poolRoot.'/pool-mirror/'.$shareName, 0755, true);
mkdir($poolRoot.'/pool-plain/'.$shareName, 0755, true);

// The "pool name" strings below are deliberately a full relative path, not a
// bare word — staxx_storage_options() only ever joins "/mnt/" + this + "/" +
// the share name, so this is the one way to point that join at a scratch
// folder actually nested inside the real appdata share, rather than
// inventing a new top-level entry under /mnt (which the real feature must
// never do either — see staxx_folder_create()'s own comment on the same
// hazard, and this file's header).
$poolMirror = 'user/appdata/staxx-storage-test/pool-mirror';
$poolPlain  = 'user/appdata/staxx-storage-test/pool-plain';

$iniD = $scratchDir.'/offered.ini';
storage_test_write_ini($iniD, [
  $poolMirror => ['type' => 'Cache', 'fsType' => 'zfs', 'fsStatus' => 'Mounted',
                  'fsProfile' => 'mirror', 'fsSize' => '2000000', 'fsFree' => '900000'],
  $poolPlain  => ['type' => 'Cache', 'fsType' => 'zfs', 'fsStatus' => 'Mounted',
                  'fsProfile' => '', 'fsSize' => '1000000', 'fsFree' => '12345'],
]);
$sharesOK = $scratchDir.'/shares-ok';
// No shareCachePool at all — accepted for any pool it is asked about, the
// same as a single-cache-pool box that has never needed to set it.
storage_test_write_share($sharesOK, $shareName, ['shareUseCache' => 'only']);

$r = staxx_storage_options($iniD, $sharesOK);
$offeredNames = array_column($r['offered'], 'name');
ok('both properly-shared, mounted pools are offered',
   in_array($poolMirror, $offeredNames, true) && in_array($poolPlain, $offeredNames, true),
   implode(',', $offeredNames));

$pools = array_values(array_filter($r['offered'], fn($e) => $e['kind'] === 'pool'));
ok('the redundant pool is ordered ahead of the one with no reported profile',
   ($pools[0]['name'] ?? '') === $poolMirror && ($pools[1]['name'] ?? '') === $poolPlain,
   implode(',', array_column($pools, 'name')));
ok('the mirrored pool is flagged redundant', $pools[0]['redundant'] === true);

$plain = current(array_filter($pools, fn($p) => $p['name'] === $poolPlain));
ok('an empty fsProfile is offered anyway', $plain !== false);
ok('...flagged NOT redundant', $plain['redundant'] === false);
ok('...with the raw empty profile preserved, not guessed at', $plain['fsProfile'] === '');
ok('free space is converted from KiB to bytes exactly (12345 KiB -> 12641280 B)',
   $plain['freeBytes'] === 12641280, (string)$plain['freeBytes']);

/* -------------- 5. a pool whose share the mover would drain onto the array */

mkdir($poolRoot.'/pool-mover/'.$shareName, 0755, true);
$poolMover = 'user/appdata/staxx-storage-test/pool-mover';

$iniE = $scratchDir.'/mover.ini';
storage_test_write_ini($iniE, [$poolMover => [
  'type' => 'Cache', 'fsType' => 'zfs', 'fsStatus' => 'Mounted',
  'fsProfile' => 'mirror', 'fsSize' => '1000', 'fsFree' => '1000',
]]);
$sharesMover = $scratchDir.'/shares-mover';
storage_test_write_share($sharesMover, $shareName, ['shareUseCache' => 'yes', 'shareCachePool' => $poolMover]);

$r = staxx_storage_options($iniE, $sharesMover);
ok('a pool whose share the mover would drain is not offered',
   !in_array($poolMover, array_column($r['offered'], 'name'), true));
$u = current(array_filter($r['unavailable'], fn($e) => $e['name'] === $poolMover));
ok('...and says why: the mover, not a made-up reason',
   $u !== false && stripos($u['reason'], 'move') !== false, json_encode($u));

/* --------------------------- 6. no existing top-level folder at all ------ */

$poolNoFolder = 'user/appdata/staxx-storage-test/pool-nofolder'; // deliberately never mkdir'd
$iniF = $scratchDir.'/nofolder.ini';
storage_test_write_ini($iniF, [$poolNoFolder => [
  'type' => 'Cache', 'fsType' => 'zfs', 'fsStatus' => 'Mounted',
  'fsProfile' => 'mirror', 'fsSize' => '1000', 'fsFree' => '1000',
]]);
$r = staxx_storage_options($iniF, $sharesOK);
$u = current(array_filter($r['unavailable'], fn($e) => $e['name'] === $poolNoFolder)) ?: [];
ok('a pool with no existing top-level folder is not silently dropped', $u !== []);
ok('...and the reason points at making the share first, not a guessed path',
   stripos($u['reason'] ?? '', 'share') !== false, json_encode($u));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
