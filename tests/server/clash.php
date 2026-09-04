<?php
/* PLAN_118 — two stacks that would run as the same compose project: the
 * list-time clash detector (staxx_project_clashes()), the state guard that
 * stops a dormant twin reading as the running one (staxx_state_for()'s
 * $clash flag), the delete guard that refuses to tear down a project it
 * does not own (staxx_archive_stack()), and staxx_name_free(), the one
 * check every door that names a stack directory calls before creating one.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzc118-store, the same way tests/server/review.php does
 * it — never the real store:
 *
 *     pscp tests/server/clash.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzc118-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzc118-store\"" >> $CFG
 *       php /tmp/clash.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzc118…", under the scratch stacks
 * folder.
 *
 * MUST NEVER RUN DOCKER against anything real. The only case that needs a
 * project to look "running" is the archive delete guard, and there is no
 * safe way to make a fake project genuinely running on this box — starting
 * one, even a throwaway alpine container, is exactly the "never pull or
 * start anything" line this machine is under. So staxx_compose_state()
 * takes an optional test-only stub (see its own docblock) that replaces its
 * memoised answer outright, in memory, with no docker command run at all.
 * That is also the point being proven: staxx_archive_stack() skips `down`
 * entirely once it decides the project belongs elsewhere, so exercising
 * that branch never risks a real `docker compose down` running against
 * anything, stubbed state or not.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/zzc118-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);
mkdir($root.'/Media', 0755, true);

$compose = "services:\n  a:\n    image: alpine:3.20\n";

// Two stacks, same leaf, different folders — the exact shape of Adrian's
// original report.
$rootRel  = 'zzc118tdarr';
$rootDir  = $root.'/'.$rootRel;
$mediaRel = 'Media/zzc118tdarr';
$mediaDir = $root.'/'.$mediaRel;
mkdir($rootDir, 0755, true);
mkdir($mediaDir, 0755, true);
file_put_contents($rootDir.'/compose.yaml', $compose);
file_put_contents($mediaDir.'/compose.yaml', $compose);

// An unrelated third stack, to prove it is left alone.
$otherRel = 'zzc118other';
$otherDir = $root.'/'.$otherRel;
mkdir($otherDir, 0755, true);
file_put_contents($otherDir.'/compose.yaml', $compose);

staxx_scan_stacks_reset();

/* -------------------------------------------------------- clash detection -- */

$clashes = staxx_project_clashes();
ok('the root stack lists the folder one as its clash',
   ($clashes[$rootRel] ?? []) === [$mediaRel]);
ok('the folder stack lists the root one as its clash',
   ($clashes[$mediaRel] ?? []) === [$rootRel]);
ok('the unrelated stack has no clash', ($clashes[$otherRel] ?? ['unset']) === []);

$rows = [];
foreach (staxx_list_stacks() as $row) $rows[$row['name']] = $row;
ok('staxx_list_stacks() carries the clash on the root row',
   ($rows[$rootRel]['clash'] ?? null) === [$mediaRel]);
ok('staxx_list_stacks() carries the clash on the folder row',
   ($rows[$mediaRel]['clash'] ?? null) === [$rootRel]);
ok('staxx_list_stacks() carries no clash on the unrelated row',
   ($rows[$otherRel]['clash'] ?? ['unset']) === []);

$states = staxx_stack_states();
ok('staxx_stack_states() carries the same clash for the cheap refresh',
   ($states[$rootRel]['clash'] ?? null) === [$mediaRel]
   && ($states[$mediaRel]['clash'] ?? null) === [$rootRel]);

/* -------------------------------------------------- the state-for guard --- */
// Stub a "running" project under the guessed name, owned by the folder
// stack's own file — proves the fallback WOULD match the root stack (the
// hazard) and that passing $clash=true stops it (the fix). No docker
// command runs; this only replaces staxx_compose_state()'s memoised answer.

$rootFile  = $rootDir.'/compose.yaml';
$mediaFile = $mediaDir.'/compose.yaml';
staxx_compose_state([
  'byFile' => [$mediaFile => ['name' => 'zzc118tdarr', 'status' => 'running(1)']],
  'byTail' => [],
  'byName' => ['zzc118tdarr' => ['name' => 'zzc118tdarr', 'status' => 'running(1)']],
]);

ok('without the guard, the dormant twin would read as running (the hazard)',
   staxx_state_for($rootFile, 'zzc118tdarr', false) !== null);
ok('with the guard, the dormant twin reads as not created instead',
   staxx_state_for($rootFile, 'zzc118tdarr', true) === null);
ok('the stack that really owns the file still reads as running',
   staxx_state_for($mediaFile, 'zzc118tdarr', true) !== null);

/* --------------------------------------------------- the delete guard ----- */
// Same stub still in place: the guessed project "zzc118tdarr" is "running"
// from the Media copy's file. Archiving the ROOT copy must skip `down`
// altogether and say so, leaving the (stubbed) running project untouched.

$error = ''; $archive = null; $note = null;
$okArchive = staxx_archive_stack($rootRel, $error, true, $archive, $note);
ok('archiving the folder that does not own the running project succeeds', $okArchive, $error);
ok('it names the project and the folder that really owns it',
   $note === 'Project "zzc118tdarr" is running from Media/zzc118tdarr, so its containers '
           . 'were left alone; only this folder was archived.',
   $note ?? '(null)');
ok('the root folder is gone from the stacks tree', !is_dir($rootDir));
ok('an archive zip was written', $archive !== null && file_exists($archive));

// Clear the stub before the next section reasons about compose state again.
staxx_compose_state(['byFile' => [], 'byTail' => [], 'byName' => []]);

// The other stack in the clash — no stub in place, so nothing is "running"
// under that guessed name at all. staxx_archive_stack() must fall through
// to running `down` as normal, which is harmless against a project docker
// has never heard of, and report no note.
$error = ''; $archive = null; $note = null;
$okArchive2 = staxx_archive_stack($mediaRel, $error, true, $archive, $note);
ok('archiving a stack whose project is not running at all still succeeds', $okArchive2, $error);
ok('nothing was skipped — there is no note to show', ($note ?? '') === '');
ok('the folder stack is gone from the stacks tree too', !is_dir($mediaDir));

staxx_scan_stacks_reset();

/* --------------------------------------------------- the creation guard --- */
// A fresh stack recreated at the clashing leaf, then every door that names a
// stack directory is asked to refuse the same name elsewhere.

mkdir($rootDir, 0755, true);
file_put_contents($rootFile, $compose);
staxx_scan_stacks_reset();

$err = '';
ok('staxx_name_free() refuses a leaf already claimed elsewhere',
   !staxx_name_free('zzc118tdarr', '', $err));
ok('the refusal names the stack and says why',
   strpos($err, 'zzc118tdarr') !== false && strpos($err, 'already exists') !== false, $err);
ok('staxx_name_free() allows a stack to keep its own current name',
   staxx_name_free('zzc118tdarr', $rootRel));
ok('staxx_name_free() has nothing to say about an unrelated leaf',
   staxx_name_free('zzc118somethingelse'));

// Door 1: a brand-new stack (staxx_save_stack()).
$newDir = $root.'/Media/zzc118tdarr';   // does not exist yet — Media already
@exec('rm -rf '.escapeshellarg($newDir));
staxx_scan_stacks_reset();
$err = '';
ok('door "new stack": staxx_save_stack() refuses the clashing leaf',
   !staxx_save_stack('Media/zzc118tdarr', $compose, $err), $err);
ok('door "new stack": nothing was created on disk', !is_dir($newDir));

// Door 2: rename (staxx_rename_stack()). Filed in Media rather than at the
// root, and Media holds no "zzc118tdarr" of its own right now — so the
// existing same-folder collision check has nothing to say here, and only
// the new store-wide clash check can refuse this.
$stage2Rel = 'Media/zzc118stage2';
$stage2Dir = $root.'/'.$stage2Rel;
mkdir($stage2Dir, 0755, true);
file_put_contents($stage2Dir.'/compose.yaml', $compose);
staxx_scan_stacks_reset();

$err = '';
ok('door "rename": refuses renaming a stack in another folder onto the clashing leaf',
   staxx_rename_stack($stage2Rel, 'zzc118tdarr', $err) === '', $err);
ok('door "rename": the stack being renamed is untouched', is_dir($stage2Dir));

// Door 3: folder move (staxx_folder_assign()). A move keeps the stack's own
// leaf — it never renames — so the clash it can produce is a leaf that
// already exists SOMEWHERE ELSE in the store, which the destination
// folder's own "already there" check cannot see because it only looks
// inside that one folder.
mkdir($root.'/Staging', 0755, true);
$stageRel = 'Staging/zzc118tdarr';
$stageDir = $root.'/'.$stageRel;
mkdir($stageDir, 0755, true);
file_put_contents($stageDir.'/compose.yaml', $compose);
staxx_scan_stacks_reset();

$err = '';
ok('door "folder move": refuses a move whose leaf already clashes elsewhere',
   staxx_folder_assign($stageRel, 'Media', $err) === '', $err);
ok('door "folder move": the stack being moved is untouched', is_dir($stageDir));

// Door 4: import (staxx_import_prepare_dir(), shared by Community
// Applications install, image import and a Compose Manager takeover).
$err = '';
$importDir = staxx_import_prepare_dir('Media/zzc118tdarr', $err);
ok('door "import": staxx_import_prepare_dir() refuses the clashing leaf',
   $importDir === '', $err);
ok('door "import": nothing was created on disk', !is_dir($newDir));

/* ------------------------------------------------------------ cleanup ---- */

@exec('rm -rf '.escapeshellarg($root));
@exec('rm -rf '.escapeshellarg(staxx_archive_root()));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
