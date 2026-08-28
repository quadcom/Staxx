<?php
/* The review lock: staxx_review_file(), staxx_review_locked(), the job-runner
 * refusal, archiving a locked stack, and that a rename or a folder move keeps
 * the lock.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. The archive case
 * needs a real zip to land somewhere other than the box's real archive
 * folder — and since PLAN_97 that folder is derived from the same store as
 * the stacks, the two can no longer be pointed in different directions the
 * way this file once did with ARCHIVE_ROOT alone. So the whole store is
 * redirected: STORE_ROOT is pointed at /tmp/zzc1-store, and every fixture
 * stack this file creates lives under the derived stacks folder inside it,
 * not on the box's real store. The CALLER sets it and puts the config back,
 * the same way tests/server/files.php does:
 *
 *     pscp tests/server/review.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzc1-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzc1-store\"" >> $CFG
 *       php /tmp/review.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzc1…", under the scratch stacks folder.
 *
 * MUST NEVER RUN DOCKER. staxx_start_job() detaches a real compose command
 * the moment it is called, so the only verb walk done against a genuine,
 * running-server-visible stack is the LOCKED one — the lock refuses before
 * any command is even built, so nothing is ever exec()'d. The "unlocked"
 * walk (case 4) is run against a stack name that is never created on disk,
 * so it always refuses at the "no compose file" check instead, for the same
 * reason and just as safely — never reaching exec() either. Nothing here
 * ever calls up, pull, restart or update against a real container. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
// Folders.php too, for the folder-move case at the end — it sits ABOVE
// Stacks.php in the include order and pulls Stacks.php in itself, so requiring
// Stacks.php alone leaves staxx_folder_create()/staxx_folder_assign() undefined
// and the run dies on the last three cases.
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------------ fixtures --- */

$root = staxx_stack_root();
if ($root !== '/tmp/zzc1-store/stacks') {
  echo "FAIL   the temporary store is not in place (got $root) — refusing to touch the real stacks\n";
  exit(1);
}

$lockedRel    = 'zzc1lock';
$plainRel     = 'zzc1plain';
$caseRel      = 'zzc1case';
$strangerRel  = 'zzc1stranger';
$moveRel      = 'zzc1move';
$movedLeaf    = 'zzc1moved';
$folderName   = 'zzc1folder';
$noneRel      = 'zzc1none';       // deliberately never created — see case 4

$lockedDir   = $root.'/'.$lockedRel;
$plainDir    = $root.'/'.$plainRel;
$caseDir     = $root.'/'.$caseRel;
$strangerDir = $root.'/'.$strangerRel;
$moveDir     = $root.'/'.$moveRel;

// Wipe anything a previous, interrupted run might have left, so this script
// can be re-run without a stale fixture causing a false failure.
$sweep = [$lockedRel, $plainRel, $caseRel, $strangerRel, $moveRel, $movedLeaf, $folderName];
foreach ($sweep as $name) @exec('rm -rf '.escapeshellarg($root.'/'.$name));

$compose = "services:\n  a:\n    image: alpine:3.20\n";

mkdir($lockedDir, 0755, true);
file_put_contents($lockedDir.'/compose.yaml', $compose);
file_put_contents($lockedDir.'/'.STAXX_REVIEW_FILE, "# Imported\n\nCheck this over, then mark it reviewed.\n");

mkdir($plainDir, 0755, true);
file_put_contents($plainDir.'/compose.yaml', $compose);

// A differently-cased copy of the lock filename. On a case-sensitive
// filesystem this sits beside a canonical one that is never written here, so
// it is the only lock file present; on vfat (the flash drive) a write to
// either name lands on the same directory entry, so this still finds
// something. Either way staxx_review_file() must come back non-empty.
mkdir($caseDir, 0755, true);
file_put_contents($caseDir.'/compose.yaml', $compose);
file_put_contents($caseDir.'/needs-review.md', 'lower-case name');

mkdir($strangerDir, 0755, true);
file_put_contents($strangerDir.'/compose.yaml', $compose);
file_put_contents($strangerDir.'/'.STAXX_REVIEW_FILE, 'imported');
file_put_contents($strangerDir.'/stranger.txt', 'not ours');

mkdir($moveDir, 0755, true);
file_put_contents($moveDir.'/compose.yaml', $compose);
file_put_contents($moveDir.'/'.STAXX_REVIEW_FILE, 'imported');

/* --------------------------------------------------- staxx_review_file --- */

ok('finds the lock file by its real name',
   staxx_review_file($lockedDir) === STAXX_REVIEW_FILE);
ok('returns empty for a stack with no lock file',
   staxx_review_file($plainDir) === '');
ok('finds a differently-cased copy',
   staxx_review_file($caseDir) !== '');

/* ------------------------------------------------- staxx_review_locked --- */

ok('locked stack reads as locked',
   staxx_review_locked($lockedRel) === true);
ok('plain stack reads as unlocked',
   staxx_review_locked($plainRel) === false);
ok('an invalid path is not locked',
   staxx_review_locked('a/b/c') === false);
ok('an unknown stack is not locked',
   staxx_review_locked($noneRel) === false);

/* -------------------------------------------- job runner: locked stack --- */

// Every verb staxx_job_verbs() knows about, at both scopes, so a verb added
// later cannot quietly escape the lock. Safe to run for real: the refusal
// happens before staxx_start_job() ever builds or launches a command.
$verbs = array_keys(staxx_job_verbs());
$badVerb = '';
foreach ($verbs as $verb) {
  $err = '';
  $r = staxx_start_job($lockedRel, $verb, $err, '');
  if ($r !== '' || $err === '' || stripos($err, 'review') === false) { $badVerb = $verb.' (whole stack)'; break; }

  $err = '';
  $r = staxx_start_job($lockedRel, $verb, $err, 'a');
  if ($r !== '' || $err === '' || stripos($err, 'review') === false) { $badVerb = $verb.' (service)'; break; }
}
ok('every verb is refused at both scopes for a locked stack', $badVerb === '', $badVerb);

/* ------------------------------------------ job runner: unlocked stack --- */

// $noneRel has no folder at all, so every one of these hits "no compose file
// found" before staxx_start_job() reaches the point of building a command —
// never the review refusal, and never a real exec() either. That is what
// proves the lock check does not fire when there is nothing to lock.
$falsePositive = '';
foreach ($verbs as $verb) {
  $err = '';
  staxx_start_job($noneRel, $verb, $err, '');
  if (stripos($err, 'review') !== false) { $falsePositive = $verb.' (whole stack): '.$err; break; }

  $err = '';
  staxx_start_job($noneRel, $verb, $err, 'a');
  if (stripos($err, 'review') !== false) { $falsePositive = $verb.' (service): '.$err; break; }
}
ok('an unlocked stack is never refused for the review reason', $falsePositive === '', $falsePositive);

/* --------------------------------------------- the row must claim nothing --
 *
 * Refusing the verbs is only half of it. A locked stack still resolves a
 * project name from its own folder, and an import's folder is named after
 * whatever it was copied FROM — so without these guards the row inherits the
 * LIVE project's running state, its containers, and (because the stats reply
 * is keyed by project) their processor and memory figures. It then reads as
 * though the import were already working, which is the green row the whole
 * review lock exists to prevent. Found by driving the page rather than by a
 * test, so pinned here. */

$states = staxx_stack_states();
ok('a locked stack reports no running state',
   ($states[$lockedRel]['running'] ?? null) === false);
ok('...and no project, so nothing keyed by project can attach to it',
   ($states[$lockedRel]['project'] ?? null) === '');

// The guard has to hold even when the project genuinely exists on this
// machine, which is the case that bites — so borrow a real project name from
// whatever is running here rather than inventing one that could never match.
// staxx_container_index(), not staxx_compose_state(): the latter is what
// `compose ls` reports and is keyed differently, so borrowing from it skipped
// this case silently on a machine running four compose projects. Read the
// index staxx_stack_containers() itself consults.
$liveProject = '';
foreach (staxx_container_index()['byProject'] as $p => $rows) {
  if ($p !== '' && $rows) { $liveProject = (string)$p; break; }
}
if ($liveProject === '') {
  ok('SKIPPED — no compose project on this machine to borrow a name from', true);
} else {
  $spoof = ['name' => $lockedRel, 'leaf' => $lockedRel, 'file' => '', 'project' => $liveProject];
  ok('a locked stack claims no containers even when its project really exists',
     staxx_stack_containers($spoof) === [], 'borrowed '.$liveProject);

  // The same lookup without the lock DOES find them, which is what makes the
  // assertion above mean something rather than passing by accident.
  $spoof['name'] = $plainRel;
  ok('...while the identical lookup unlocked finds them, so the guard is what stopped it',
     staxx_stack_containers($spoof) !== [], 'borrowed '.$liveProject);
}

/* ------------------------------------------------------- staxx_list_files -- */

file_put_contents($lockedDir.'/aardvark.txt', 'sorts before N alphabetically');

$err = '';
$list = staxx_list_files($lockedRel, $err);
ok('lists the locked stack', is_array($list), $err);

$names = array_column((array)$list, 'name');
ok('compose file is still first', ($names[0] ?? '') === 'compose.yaml');
ok('lock file sorts immediately after the compose file, ahead of an earlier name',
   ($names[1] ?? '') === STAXX_REVIEW_FILE, implode(', ', $names));

$byName = [];
foreach ((array)$list as $e) $byName[$e['name']] = $e;
ok('the lock file is flagged review => true', ($byName[STAXX_REVIEW_FILE]['review'] ?? null) === true);
ok('an ordinary file is flagged review => false', ($byName['aardvark.txt']['review'] ?? null) === false);

/* ----------------------------------------------------- archiving a lock --- */

// A locked stack's containers belong to whatever it was imported from, so
// archiving one must never tear them down — the lock is what skips the
// compose "down" entirely. Nothing here can reach Docker for that reason.
$archiveRoot = staxx_archive_root();
if ($archiveRoot !== '/tmp/zzc1-store/archives') {
  echo "FAIL   the temporary archive root is not in place (got $archiveRoot)
";
  exit(1);
}
@exec('rm -rf '.escapeshellarg($archiveRoot));
mkdir($archiveRoot, 0755, true);

$err = '';
$archive = null;
ok('an unconfirmed archive refuses whatever is in the folder',
   !staxx_archive_stack($lockedRel, $err, false, $archive), $err);
ok('and leaves the lock file where it was', file_exists($lockedDir.'/'.STAXX_REVIEW_FILE));

$err = '';
ok('a locked stack archives once confirmed',
   staxx_archive_stack($lockedRel, $err, true, $archive), $err);
ok('its folder is gone', !is_dir($lockedDir));
ok('and the lock file went into the zip',
   strpos(staxx_sh('unzip -l '.escapeshellarg((string)$archive)),
          STAXX_REVIEW_FILE) !== false);

// A file nobody here wrote used to block removal outright, because it was
// about to be destroyed. It is kept now, so it blocks nothing — it just
// travels into the zip with everything else.
$err = '';
$strangerArchive = null;
ok('a stranger file no longer blocks anything',
   staxx_archive_stack($strangerRel, $err, true, $strangerArchive), $err);
ok('the stranger stack folder is gone', !is_dir($strangerDir));
ok('and the stranger file is in the archive',
   strpos(staxx_sh('unzip -l '.escapeshellarg((string)$strangerArchive)),
          'stranger.txt') !== false);

@exec('rm -rf '.escapeshellarg($archiveRoot));

/* ------------------------------------------------ rename and folder move -- */

// The whole design rests on this: nothing in either path touches the lock
// file, so a plain move is what keeps a locked stack locked.
$err = '';
$renamed = staxx_rename_stack($moveRel, $movedLeaf, $err);
ok('renames the locked stack', $renamed !== '', $err);
ok('it is still locked after the rename', staxx_review_locked($renamed) === true);

$err = '';
$made = staxx_folder_create($folderName, $err);
ok('creates the test folder', $made !== '', $err);

$err = '';
$movedRel = staxx_folder_assign($renamed, $folderName, $err);
ok('files the stack into the folder', $movedRel !== '', $err);
ok('it is still locked after the folder move', staxx_review_locked($movedRel) === true);

/* ------------------------------------------------------------- cleanup --- */

foreach ($sweep as $name) @exec('rm -rf '.escapeshellarg($root.'/'.$name));
ok('nothing left behind', !is_dir($lockedDir) && !is_dir($plainDir) && !is_dir($caseDir)
                        && !is_dir($strangerDir) && !is_dir($moveDir)
                        && !is_dir($root.'/'.$movedLeaf) && !is_dir($root.'/'.$folderName));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
