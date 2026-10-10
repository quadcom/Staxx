<?php
/* PLAN_82 Part 1 — per-stack image history (include/ImageHistory.php), and
 * the safety-critical keep-set the Scan stored images window and the
 * storage alert build from it. Also covers the Part 2
 * step 3 round trip of the three optional release-notes keys through the
 * real push/read functions — the pure shape rules for those keys live in
 * tests/server/releasenotes.php instead, alongside everything else that
 * plan step added.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b3-store, the same way tests/server/record.php points
 * STORE_ROOT at /tmp/b2-store — never the real stack root. STORE_ROOT is one
 * of the only three keys the flash pointer file may hold (STAXX_FLASH_KEYS
 * in Defines.php), so it is seeded there before php runs, the same as ever.
 * UPDATE_RETAIN is not one of those three — every other setting lives in the
 * store's own config file — so this script seeds UPDATE_RETAIN="3" into
 * /tmp/b3-store/config/staxx.cfg itself, before ImageHistory.php's first
 * require (see there): staxx_cfg() memoises the first time it is read, so
 * seeding it any later would already be too late.
 *
 *     pscp tests/server/run-with-store.sh tests/server/imagehistory.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/b3-store /tmp/imagehistory.php'
 *
 * The update-state file lives on the flash drive too
 * (/boot/config/plugins/staxx/updates.json) and is NEVER touched — this
 * script points STAXX_UPDATE_STATE at a scratch file in /tmp via putenv(),
 * before the first require, and checks the override actually took before
 * doing anything else. That file is read once and cached the same way
 * staxx_cfg() is, so the putenv() has to happen before Updates.php is
 * required, not after.
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzb3…", under the temporary stack root, and its
 * own scratch state file. Cleans up on the way in too, so a previous
 * interrupted run cannot affect this one.
 *
 * Every case here is read-only or writes to files under /tmp — nothing in
 * this file starts, stops, pulls or removes a container or an image. */

$scratch = '/tmp/staxx-imagehistory-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

// UPDATE_RETAIN is not a flash key (see this file's header) — seeded here,
// into the scratch store's own config file, before UpdateRun.php's first
// require just below, which is the earliest staxx_cfg() could be called.
$b3CfgFile = '/tmp/b3-store/config/staxx.cfg';
@mkdir('/tmp/b3-store/config', 0755, true);
file_put_contents($b3CfgFile, "UPDATE_RETAIN=\"3\"\n");

register_shutdown_function(function () use ($scratch, $b3CfgFile) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
  @unlink($b3CfgFile);
});

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* A digest has to look like a real one. The reader refuses anything that is
 * not "algo:" plus at least 32 hex characters, so a short fake would be
 * written and then never read back — which is exactly the asymmetry this
 * suite caught. dg() keeps each label readable in the output while producing
 * something the reader accepts. */
function dg(string $label): string {
  return 'sha256:'.substr(hash('sha256', $label), 0, 64);
}

if (staxx_stack_root() !== '/tmp/b3-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_update_state_file() !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".staxx_update_state_file().")\n";
  exit(1);
}
if (staxx_update_settings()['retain'] !== 3) {
  echo "FAIL   UPDATE_RETAIN is not seeded as 3 (got ".staxx_update_settings()['retain'].")\n";
  exit(1);
}

$root = staxx_stack_root();

/* Clean slate on the way in — an interrupted previous run must not be able
 * to feed stale fixtures into this one — and again at the bottom. */
function b3_wipe(): void {
  global $root, $scratch;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @unlink($scratch);
}
b3_wipe();

function b3_make_stack(string $rel, string $service = 'web', string $image = 'alpine:3.20'): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', "services:\n  $service:\n    image: $image\n");

  // The stack list is memoised for the rest of the request, and every writer
  // in Stacks.php that changes the tree calls this for exactly that reason.
  // A fixture built by hand has to do the same, or a stack created after
  // something has already looked is invisible for the whole run — which reads
  // as a bug in the code under test rather than in the fixture.
  staxx_scan_stacks_reset();
}

/* ------------------------------------------------------- push and read -- */

b3_make_stack('zzb3basic', 'web');

staxx_image_history_push('zzb3basic', 'web', dg('aaa1'), []);
$list = staxx_image_history('zzb3basic', 'web');
ok('a fresh push records exactly one entry', count($list) === 1, 'count='.count($list));
ok('...with the digest just pushed', ($list[0]['digest'] ?? '') === dg('aaa1'));

staxx_image_history_push('zzb3basic', 'web', dg('aaa1'), []);
$list = staxx_image_history('zzb3basic', 'web');
ok('pushing the same digest twice running records it once', count($list) === 1, 'count='.count($list));

staxx_image_history_push('zzb3basic', 'web', dg('bbb2'), []);
$list = staxx_image_history('zzb3basic', 'web');
ok('a genuinely different digest is recorded', count($list) === 2, 'count='.count($list));

staxx_image_history_push('zzb3basic', 'web', dg('aaa1'), []);
$list = staxx_image_history('zzb3basic', 'web');
$digests = array_column($list, 'digest');
ok('pushing an earlier digest again (not twice RUNNING) records a third entry',
   count($list) === 3, 'count='.count($list));
ok('newest first: aaa1, bbb2, aaa1',
   $digests === [dg('aaa1'), dg('bbb2'), dg('aaa1')], 'got='.implode(',', $digests));

ok('reading an unrecorded service gives an empty list',
   staxx_image_history('zzb3basic', 'noservice') === []);
ok('reading an unrecorded stack gives an empty list',
   staxx_image_history('zzb3nostack', 'web') === []);

/* ------------------------------------------------------- retention cap -- */

b3_make_stack('zzb3prune', 'web');
foreach ([dg('p1'), dg('p2'), dg('p3'), dg('p4')] as $d) {
  staxx_image_history_push('zzb3prune', 'web', $d, []);
}
$list = staxx_image_history('zzb3prune', 'web');
$digests = array_column($list, 'digest');
ok('the retention cap trims to exactly the configured count',
   count($list) === 3, 'count='.count($list));
ok('...keeping the three NEWEST, oldest dropped',
   $digests === [dg('p4'), dg('p3'), dg('p2')], 'got='.implode(',', $digests));

/* -------------------------------------------------- an ordinary blank row -- */

b3_make_stack('zzb3blank', 'web');
staxx_image_history_push('zzb3blank', 'web', dg('blank1'), []);
$row = staxx_image_history('zzb3blank', 'web')[0] ?? null;
ok('a push with no meta at all still produces one row', $row !== null);
ok('...with an empty version', is_array($row) && ($row['version'] ?? null) === '');
ok('...with an empty source', is_array($row) && ($row['source'] ?? null) === '');

staxx_image_history_push('zzb3blank', 'web', dg('blank2'), ['version' => 'v10.9.11', 'source' => 'https://example.test/proj']);
$row = staxx_image_history('zzb3blank', 'web')[0] ?? null;
ok('a push with both fields keeps them verbatim',
   is_array($row) && $row['version'] === 'v10.9.11' && $row['source'] === 'https://example.test/proj');

/* ------------------------------------------ PLAN_82 Part 2 step 3: notes -- *
 * The three release-notes keys (notes, notesUrl, notesCut) are optional and
 * additive on a pushed entry — see the comment above staxx_image_history_
 * push() in ImageHistory.php. The pure shape rules for these keys (absent
 * is valid, present must be the right type, one wrong type takes the whole
 * map down) are proved directly against staxx_image_history_valid_entry()/
 * staxx_image_history_valid_map() in tests/server/releasenotes.php; what
 * belongs here is the round trip through the real functions this suite
 * already exercises everywhere else — a push with notes meta actually
 * WRITES the three keys, and a push with none leaves the entry exactly the
 * small shape it always was. */

b3_make_stack('zzb3notes', 'web');
staxx_image_history_push('zzb3notes', 'web', dg('notes1'), [
  'version'   => '1.2.3',
  'notes'     => 'Fixed a bug. Added a feature.',
  'notesUrl'  => 'https://github.com/example/project/releases/tag/1.2.3',
  'notesCut'  => true,
]);
$notesRow = staxx_image_history('zzb3notes', 'web')[0] ?? null;
ok('a push with notes meta writes all three keys verbatim',
   is_array($notesRow)
     && $notesRow['notes'] === 'Fixed a bug. Added a feature.'
     && $notesRow['notesUrl'] === 'https://github.com/example/project/releases/tag/1.2.3'
     && $notesRow['notesCut'] === true,
   $notesRow === null ? '(nothing recorded)' : json_encode($notesRow));

staxx_image_history_push('zzb3notes', 'web', dg('notes2'), ['version' => '1.2.4']);
$noNotesRow = staxx_image_history('zzb3notes', 'web')[0] ?? null;
ok('a push with no notes meta leaves the entry with no notes keys at all — '
 . 'the small shape every entry had before this change',
   is_array($noNotesRow)
     && !array_key_exists('notes', $noNotesRow)
     && !array_key_exists('notesUrl', $noNotesRow)
     && !array_key_exists('notesCut', $noNotesRow),
   $noNotesRow === null ? '(nothing recorded)' : json_encode($noNotesRow));

// A blank notes string is the same as no notes at all — nothing to show, so
// nothing is written, per staxx_image_history_push()'s own "written only
// when there is something to write" rule.
staxx_image_history_push('zzb3notes', 'web', dg('notes3'), ['version' => '1.2.5', 'notes' => '']);
$blankNotesRow = staxx_image_history('zzb3notes', 'web')[0] ?? null;
ok('a push with an empty notes string writes no notes key either',
   is_array($blankNotesRow) && !array_key_exists('notes', $blankNotesRow),
   $blankNotesRow === null ? '(nothing recorded)' : json_encode($blankNotesRow));

/* ----------------------------------------------- digests mirror entries -- */

$entries = staxx_image_history('zzb3basic', 'web');
$viaDigests = staxx_image_history_digests('zzb3basic', 'web');
ok('staxx_image_history_digests() matches the full entries, same order',
   $viaDigests === array_column($entries, 'digest'));

/* ------------------------------------------------------------ all stacks -- */

$all = staxx_image_history_all();
ok('staxx_image_history_all() lists every stack::service with history',
   ($all['zzb3basic::web'] ?? null) === array_column($entries, 'digest'));
ok('...and one with no history at all is simply absent',
   !array_key_exists('zzb3blank::noservice', $all));

/* ------------------------------------------------------------- renaming -- */

b3_make_stack('zzb3renameA', 'web');
staxx_image_history_push('zzb3renameA', 'web', dg('rn1'), ['version' => '1.0']);

rename($root.'/zzb3renameA', $root.'/zzb3renameB');

ok('the record followed the folder to its new name',
   staxx_image_history_digests('zzb3renameB', 'web') === [dg('rn1')]);
ok('nothing is recorded under the old name any more — there is no folder there to hold it',
   staxx_image_history('zzb3renameA', 'web') === []);

/* -------------------------------------------------- the keep-set -- */

/* The safety-critical one. staxx_update_keep_digests() is what decides which
 * images `docker rmi` may touch, so it is called here directly — an earlier
 * draft of this suite rebuilt the union by hand and compared, which proves
 * the test and not the code. Nothing below runs docker or removes anything;
 * only the list is examined. */

b3_wipe();
b3_make_stack('zzb3keepa', 'web', 'ghcr.io/example/keepa:latest');
b3_make_stack('zzb3keepb', 'web', 'ghcr.io/example/keepb:latest');

// One digest per stack, each recorded in that stack's own record.
staxx_image_history_push('zzb3keepa', 'web', dg('keepa'), []);
staxx_image_history_push('zzb3keepb', 'web', dg('keepb'), []);

$keep = staxx_update_keep_digests();
$flat = [];
foreach ($keep as $repo => $digests) foreach ($digests as $d) $flat[] = $d;

ok('a digest held in each stack own record is kept',
   in_array(dg('keepa'), $flat, true) && in_array(dg('keepb'), $flat, true),
   implode(' ', array_map(fn($d) => substr($d, 7, 8), $flat)));
ok('...and each is grouped under its own repository, not merged together',
   count($keep) === 2, implode(',', array_keys($keep)));

/* ---------------------------------------------------------------------- */

b3_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
