<?php
/* PLAN_82 Part 1 — per-stack image history (include/ImageHistory.php), the
 * migration out of the old server-wide history file, and the safety-critical
 * keep-list that image cleanup builds from both.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STACK_ROOT
 * pointed at /tmp/b3-root and UPDATE_RETAIN at "3" (so the retention cases
 * below assert on a small, exact number rather than the real default), the
 * same way tests/server/record.php points STACK_ROOT at /tmp/b2-root —
 * never the real stack root. staxx_cfg() memoises the first time it is
 * read, so both keys are seeded into the config file BEFORE php runs, not
 * changed from inside this script — by the time this file's first line
 * executes it is already too late to move the root out from under it.
 *
 *     pscp tests/server/imagehistory.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/b3-root\"#" $CFG
 *       grep -q "^STACK_ROOT=" $CFG || echo "STACK_ROOT=\"/tmp/b3-root\"" >> $CFG
 *       sed -i "s#^UPDATE_RETAIN=.*#UPDATE_RETAIN=\"3\"#" $CFG
 *       grep -q "^UPDATE_RETAIN=" $CFG || echo "UPDATE_RETAIN=\"3\"" >> $CFG
 *       php /tmp/imagehistory.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * The central update-state file lives on the flash drive too
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
 * this file starts, stops, pulls or removes a container or an image. The
 * one call to staxx_update_cleanup() is a dry run against image references
 * that do not exist on this box, so it can only ever find nothing to
 * remove; see the note above that section for exactly what it does and does
 * not prove. */

$scratch = '/tmp/staxx-imagehistory-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
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

if (staxx_stack_root() !== '/tmp/b3-root') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (STAXX_UPDATE_STATE !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".STAXX_UPDATE_STATE.")\n";
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

/* Directly replaces just the 'history' key of the central state file,
 * leaving everything else staxx_update_state_save() already merges over
 * (paused, images, and so on) alone — the same merge-not-replace contract
 * staxx_update_state_save() itself promises. */
function b3_set_central_history(array $history): void {
  staxx_update_state_save(['history' => $history]);
}

function b3_central_history(): array {
  return (array)staxx_update_state()['history'];
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

/* ---------------------------------------------------------- the migration -- */

b3_make_stack('zzb3live1', 'web');
b3_make_stack('zzb3live2', 'db');

b3_set_central_history([
  'zzb3live1::web' => [dg('c1old'), dg('c1new')],
  'zzb3live2::db'  => [dg('c2only')],
  'other::marker'  => [dg('untouched')], // proves adopt() only ever touches the stack it was asked about
]);

$adoptErr = '';
ok('adopting a stack whose central entries map to a stack that exists succeeds',
   staxx_image_history_adopt('zzb3live1', $adoptErr), $adoptErr);

$moved = staxx_image_history('zzb3live1', 'web');
$movedDigests = array_column($moved, 'digest');
ok('the moved entries are now in the stack\'s own record',
   $movedDigests === [dg('c1old'), dg('c1new')] || $movedDigests === [dg('c1new'), dg('c1old')],
   'got='.implode(',', $movedDigests));
ok('...and both digests made it across, none dropped',
   count($movedDigests) === 2 && in_array(dg('c1old'), $movedDigests, true)
     && in_array(dg('c1new'), $movedDigests, true));

$centralAfter = b3_central_history();
ok('the central file\'s entry for the adopted stack is now emptied out',
   empty($centralAfter['zzb3live1::web']));
ok('the central file\'s OTHER contents are left alone',
   ($centralAfter['zzb3live2::db'] ?? null) === [dg('c2only')]
   && ($centralAfter['other::marker'] ?? null) === [dg('untouched')]);

/* Re-running adopt on the same stack must not duplicate anything — this is
 * the whole point of "a migration runs once and must be able to run twice". */
$adoptErr = '';
ok('adopting the same stack a second time still succeeds',
   staxx_image_history_adopt('zzb3live1', $adoptErr), $adoptErr);
ok('...and did not duplicate a single entry',
   count(staxx_image_history('zzb3live1', 'web')) === 2,
   'count='.count(staxx_image_history('zzb3live1', 'web')));

/* An entry already in the record must not be re-added by adopting an older
 * central copy of the very same digest. */
b3_set_central_history(array_merge(b3_central_history(), [
  'zzb3live1::web' => [dg('c1old')], // a stale central copy of one already-adopted digest
]));
$adoptErr = '';
staxx_image_history_adopt('zzb3live1', $adoptErr);
ok('re-adopting a digest already present in the record does not duplicate it',
   count(staxx_image_history('zzb3live1', 'web')) === 2,
   'count='.count(staxx_image_history('zzb3live1', 'web')));

/* Adopting a stack that does not exist at all is a plain, quiet no-op — not
 * a crash, and not a way to create a stack folder out of thin air. */
$adoptErr = '';
$adoptResult = staxx_image_history_adopt('zzb3neverexisted', $adoptErr);
ok('adopting a stack with no folder at all does not create one',
   !is_dir($root.'/zzb3neverexisted'));

/* ----------------------------------------------------------------- sweep -- */

b3_make_stack('zzb3sweeplive1', 'app');
b3_make_stack('zzb3sweeplive2', 'app');

b3_set_central_history([
  'zzb3sweeplive1::app' => [dg('sl1')],
  'zzb3sweeplive2::app' => [dg('sl2')],
  'zzb3orphanA::x'      => [dg('oa')],
  'zzb3orphanB::y'      => [dg('ob')],
  'zzb3orphanC::z'      => [dg('oc')],
]);

$sweepMoved = 0;
$sweepDropped = 0;
$sweepErr = '';
ok('the sweep runs cleanly over a mix of live stacks and orphans',
   staxx_image_history_sweep($sweepMoved, $sweepDropped, $sweepErr), $sweepErr);
ok('exactly three orphan entries were dropped — the built-in fixture count',
   $sweepDropped === 3, 'dropped='.$sweepDropped);
ok('the two live stacks were moved (not dropped)',
   $sweepMoved === 2, 'moved='.$sweepMoved);
ok('the first live stack\'s digest actually landed in its own record',
   in_array(dg('sl1'), staxx_image_history_digests('zzb3sweeplive1', 'app'), true));
ok('the second live stack\'s digest actually landed in its own record',
   in_array(dg('sl2'), staxx_image_history_digests('zzb3sweeplive2', 'app'), true));

$centralAfterSweep = b3_central_history();
ok('none of the three orphan keys survive the sweep',
   !isset($centralAfterSweep['zzb3orphanA::x'])
   && !isset($centralAfterSweep['zzb3orphanB::y'])
   && !isset($centralAfterSweep['zzb3orphanC::z']));

/* Running the sweep again over an already-clean file must do nothing and
 * still succeed. */
$sweepMoved = 0; $sweepDropped = 0; $sweepErr = '';
ok('sweeping an already-swept file succeeds and finds nothing left to do',
   staxx_image_history_sweep($sweepMoved, $sweepDropped, $sweepErr) && $sweepMoved === 0 && $sweepDropped === 0,
   'moved='.$sweepMoved.' dropped='.$sweepDropped.' err='.$sweepErr);

/* ------------------------------------------------------------- renaming -- */

b3_make_stack('zzb3renameA', 'web');
staxx_image_history_push('zzb3renameA', 'web', dg('rn1'), ['version' => '1.0']);

rename($root.'/zzb3renameA', $root.'/zzb3renameB');

ok('the record followed the folder to its new name',
   staxx_image_history_digests('zzb3renameB', 'web') === [dg('rn1')]);
ok('nothing is recorded under the old name any more — there is no folder there to hold it',
   staxx_image_history('zzb3renameA', 'web') === []);

// The central file, meanwhile, still carries a stale key for the OLD name —
// exactly the situation an update pass running before the rename recorded
// would leave behind.
b3_set_central_history(array_merge(b3_central_history(), [
  'zzb3renameA::web' => [dg('stalecentral')],
]));

$sweepMoved = 0; $sweepDropped = 0; $sweepErr = '';
staxx_image_history_sweep($sweepMoved, $sweepDropped, $sweepErr);
ok('the sweep drops the stale key left behind by the rename, not the live one',
   $sweepDropped === 1, 'dropped='.$sweepDropped);
ok('...and the renamed stack\'s own history is untouched by that drop',
   staxx_image_history_digests('zzb3renameB', 'web') === [dg('rn1')]);
ok('...and nothing was ever created under the old, now-gone name',
   !is_dir($root.'/zzb3renameA'));

/* --------------------------------------------------- the cleanup keep-list -- */
//
// staxx_update_cleanup() is the safety-critical consumer: its keep-list is
// what decides which images `docker rmi` is allowed to touch, and a digest
// missing from it is an image deleted while a rollback still needs it.
//
// Reading include/UpdateRun.php as it stood when this file was written, the
// keep-set it builds comes from two sources: staxx_update_state()['images']
// (the live pointer for each tracked image) and staxx_update_state()
// ['history'] (the OLD central history, matched back to whichever stack
// currently uses that image). This test file's whole point is the
// migration OFF that second source and onto each stack's own record — so
// whichever agent wires staxx_update_cleanup() to also read
// staxx_image_history_all() (or the per-stack functions directly) changes
// exactly the code this section is about. Since that wiring is being done
// in parallel with this file rather than before it, this section cannot
// safely assert cleanup() actually consults the migrated data by calling
// cleanup() itself and inspecting what it decided to keep — that would
// either pass for the wrong reason (against the OLD, central-only reading)
// or need to hardcode assumptions about a shape not yet written.
//
// What IS proven here: a digest that exists only in the central file, and a
// digest that exists only in a stack's own record, both surface — with no
// duplicate — from the two primitives any correct merge has to be built
// from. Once staxx_update_cleanup() is wired to combine them, re-running
// this file after that lands and calling staxx_update_cleanup(true, $err)
// against these same fixtures (fake, non-existent image references, so a
// dry run can only ever find nothing installed to remove) is a smoke test
// that the wiring did not fatal — done below — but is NOT proof the merge
// itself is correct. That proof belongs in tests/server/updaterun.php,
// against whatever shape the merge actually takes.

b3_make_stack('zzb3keepcentral', 'web');
b3_make_stack('zzb3keeprecord', 'web');

b3_set_central_history([
  'zzb3keepcentral::web' => [dg('keepcentralonly')],
]);
staxx_image_history_push('zzb3keeprecord', 'web', dg('keeprecordonly'), []);

/* -------------------------------------------------- the keep-set -- */

/* The safety-critical one. staxx_update_keep_digests() is what decides which
 * images `docker rmi` may touch, so it is called here directly — an earlier
 * draft of this suite rebuilt the union by hand and compared, which proves
 * the test and not the code. Nothing below runs docker or removes anything;
 * only the list is examined. */

b3_wipe();
b3_make_stack('zzb3keepa', 'web', 'ghcr.io/example/keepa:latest');
b3_make_stack('zzb3keepb', 'web', 'ghcr.io/example/keepb:latest');

// One digest recorded ONLY in the old central file, for a stack that exists.
b3_set_central_history(['zzb3keepa::web' => [dg('keepcentralonly')]]);

// One digest recorded ONLY in a stack's own record.
staxx_image_history_push('zzb3keepb', 'web', dg('keeprecordonly'), []);

$keep = staxx_update_keep_digests();
$flat = [];
foreach ($keep as $repo => $digests) foreach ($digests as $d) $flat[] = $d;

ok('a digest held only in the old central file is kept',
   in_array(dg('keepcentralonly'), $flat, true),
   implode(' ', array_map(fn($d) => substr($d, 7, 8), $flat)));
ok('a digest held only in a stack\'s own record is kept',
   in_array(dg('keeprecordonly'), $flat, true),
   implode(' ', array_map(fn($d) => substr($d, 7, 8), $flat)));
ok('...and both are grouped under their own repository, not merged together',
   count($keep) === 2, implode(',', array_keys($keep)));

// Both sources naming the same stack and service: the digest must appear once,
// not twice, or the keep-set grows without bound as migrations happen.
b3_wipe();
b3_make_stack('zzb3keepc', 'web', 'ghcr.io/example/keepc:latest');
staxx_image_history_push('zzb3keepc', 'web', dg('c1new'), []);
b3_set_central_history(['zzb3keepc::web' => [dg('c1new'), dg('c1old')]]);

$keep = staxx_update_keep_digests();
$flat = [];
foreach ($keep as $digests) foreach ($digests as $d) $flat[] = $d;

ok('a digest in BOTH sources is kept exactly once',
   count(array_keys($flat, dg('c1new'), true)) === 1,
   'occurrences='.count(array_keys($flat, dg('c1new'), true)));
ok('...and the one only the central file knows about is kept too',
   in_array(dg('c1old'), $flat, true));

// A stack that no longer exists must not contribute — but must not throw
// either, since a stale central entry is exactly what the sweep exists for.
b3_set_central_history([
  'zzb3keepc::web'  => [dg('c1old')],
  'zzb3gone::web'   => [dg('stalecentral')],
]);
$keep = staxx_update_keep_digests();
$flat = [];
foreach ($keep as $digests) foreach ($digests as $d) $flat[] = $d;
ok('a stale entry for a stack that no longer exists is skipped, not fatal',
   !in_array(dg('stalecentral'), $flat, true));
ok('...and the live stack alongside it is unaffected',
   in_array(dg('c1old'), $flat, true));

// Smoke test only — see the long comment above. Fake references that exist
// on no registry and are installed on no box, so a dry run can only ever
// find nothing to remove; this proves the call does not fatal, nothing more.
$cleanupErr = '';
$cleanupResult = staxx_update_cleanup(true, $cleanupErr);
ok('a dry-run cleanup after this fixture data does not fatal',
   is_array($cleanupResult) && array_key_exists('removed', $cleanupResult) && array_key_exists('kept', $cleanupResult),
   $cleanupErr);

/* ---------------------------------------------------------------------- */

b3_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
