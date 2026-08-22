<?php
/* PLAN_68 Part B, piece 2 — moving the stacks folder, checked against the
 * real installed Relocate.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/relocate.php root@<box>:/tmp/
 *     plink … "php /tmp/relocate.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * THIS TEST WRITES TO THE REAL CONFIG FILE, the same as tests/server/settings.php
 * already does — a successful move ends by calling staxx_settings_save(), which
 * is the real settings-save path this feature is required to use, not a stand-in
 * for it. The real config is backed up before anything runs and restored on
 * every exit path, including a fatal error, via register_shutdown_function(),
 * exactly settings.php's own approach. Because that save also runs
 * apply_settings for real, this can briefly re-touch Docker Hub sign-in and the
 * update-check cron entry if either is already configured on this box; both
 * come back unchanged, since neither setting is altered here.
 *
 * Unlike most tests here, the throwaway stacks folder cannot live under /tmp:
 * staxx_relocate_refuse() reuses the real STACK_ROOT path validator, which only
 * ever accepts a path under /mnt/ (a real share or disk) or under the plugin's
 * own config folder — never /tmp, which is a RAM disk the validator exists
 * partly to catch. So the throwaway source and every throwaway destination
 * below live under /mnt/user/appdata/, each in its own clearly-named scratch
 * folder, removed again on every exit path. They sit INSIDE an existing share
 * rather than directly under /mnt/user, because a directory made straight
 * inside /mnt/user is a whole new top-level share with whatever defaults
 * happen to apply — staxx_folder_create() refuses to do that for exactly this
 * reason, and a test has no more business inventing six shares than the
 * plugin does. This NEVER touches the real stacks folder, the real archive
 * folder, or any real appdata. One case is the deliberate exception: proving
 * the trial run catches a genuine case clash needs a filesystem that folds
 * case, which no array disk or pool does, so that one scratch folder lives
 * on the flash drive instead — created empty, removed within the same call,
 * and removed again defensively straight after.
 *
 * staxx_cfg() memoises the config on first read for the life of the process, so
 * STACK_ROOT/ARCHIVE_ROOT are pointed at their throwaway values once, before
 * anything here calls staxx_stack_root() for the first time, and are never
 * changed again mid-run — exactly the constraint tests/server/moves.php's own
 * comment explains. That is also why the one case that actually succeeds (and
 * so switches STACK_ROOT for real, and deletes the source) has to run LAST:
 * every case before it must leave the throwaway source and the config alone.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$cfgFile  = '/boot/config/plugins/staxx/staxx.cfg';
$source   = '/mnt/user/appdata/staxx-relocate-test-src';
$archive  = '/mnt/user/appdata/staxx-relocate-test-archive';
$destHold = '/mnt/user/appdata/staxx-relocate-test-dst-holds';
$destCorr = '/mnt/user/appdata/staxx-relocate-test-dst-corrupt';
$destMiss = '/mnt/user/appdata/staxx-relocate-test-dst-missing';
$destOK   = '/mnt/user/appdata/staxx-relocate-test-dst-success';
$destLock = '/mnt/user/appdata/staxx-relocate-test-dst-trial-locked';
$destLink = '/mnt/user/appdata/staxx-relocate-test-dst-trial-link';
$allDest  = [$destHold, $destCorr, $destMiss, $destOK, $destLock, $destLink];

// The case-clash test needs a genuinely case-insensitive filesystem to prove
// the check catches a real collision rather than one this test invented —
// the only one on an Unraid box is the flash drive itself, so this one
// scratch folder lives there instead of under appdata. It is created empty,
// removed by the trial run's own cleanup within the same call, and removed
// again defensively straight after — a few bytes, once, not a habit.
$destCase = '/boot/config/plugins/staxx/staxx-relocate-test-dst-trial-case';

$composeFixture = "# a hand-written comment that must survive\n"
                . "services:\n"
                . "  web: &base\n"
                . "    image: alpine:3.20 # inline comment\n"
                . "  worker:\n"
                . "    <<: *base\n";
$notesFixture = "a file the tool does not recognise\n";

$cfgBackup = @file_get_contents($cfgFile);

register_shutdown_function(function () use ($cfgFile, $cfgBackup, $source, $allDest, $destCase) {
  if ($cfgBackup === false) { @unlink($cfgFile); } else { @file_put_contents($cfgFile, $cfgBackup); }
  @exec('rm -rf '.escapeshellarg($source));
  @exec('rm -rf '.escapeshellarg($source.'.aside'));
  foreach ($allDest as $d) { @chmod($d, 0755); @exec('rm -rf '.escapeshellarg($d)); }
  @unlink('/mnt/user/appdata/staxx-relocate-test-blocker');
  @exec('rm -rf '.escapeshellarg($destCase));
  echo "config restored, scratch folders removed\n";
});

/* ---- point STACK_ROOT and ARCHIVE_ROOT at throwaway trees, before staxx_cfg() is ever asked ---- */
$cfgLines = $cfgBackup !== false ? preg_split('/\r?\n/', $cfgBackup) : [];
$cfgLines = array_values(array_filter($cfgLines, fn($l) =>
  strpos(trim((string)$l), 'STACK_ROOT=') !== 0 && strpos(trim((string)$l), 'ARCHIVE_ROOT=') !== 0
));
$cfgLines[] = 'STACK_ROOT="'.$source.'"';
$cfgLines[] = 'ARCHIVE_ROOT="'.$archive.'"';
@file_put_contents($cfgFile, implode("\n", $cfgLines)."\n");

ok('the throwaway stack root is in force',   staxx_stack_root()   === $source,  staxx_stack_root());
ok('the throwaway archive root is in force', staxx_archive_root() === $archive, staxx_archive_root());

// Not an assertion that is allowed to fail and carry on. Everything below
// moves and deletes whatever the stack root points at, so if the seeding did
// not take, the next few hundred lines operate on the server's own stacks.
// Stop dead instead — a failed guard is not a reason to keep going, it is the
// reason the guard exists.
if (staxx_stack_root() !== $source) {
  echo "
REFUSING TO RUN: the throwaway stack root did not take effect, so these cases would
"
     . "act on the real stacks folder (".staxx_stack_root()."). Nothing was done.
";
  exit(1);
}

/** (Re)build the fixture stack tree: one stack, a compose file with a comment
 *  and an anchor/alias, a file the tool has no opinion about, and two
 *  symlinks — one pointing at a sibling inside the stack, one pointing
 *  somewhere outside the stacks tree entirely. */
function relocate_test_build(string $root, string $compose, string $notes): void {
  @exec('rm -rf '.escapeshellarg($root));
  $stack = $root.'/demo';
  mkdir($stack, 0755, true);
  file_put_contents($stack.'/compose.yaml', $compose);
  file_put_contents($stack.'/notes.txt', $notes);
  @symlink('notes.txt', $stack.'/inside-link');
  @symlink('/etc/hostname', $stack.'/outside-link');
}

relocate_test_build($source, $composeFixture, $notesFixture);

/* ------------------------------------------------- 1. refused up front ---- */

$err = '';
$r = staxx_relocate_refuse($source, $err);
ok('a destination equal to the source is refused', $r === '' && $err !== '', $err);

$err = '';
$r = staxx_relocate_refuse($source.'/inside', $err);
ok('a destination inside the source is refused', $r === '' && $err !== '', $err);

$err = '';
$r = staxx_relocate_refuse(dirname($source), $err); // /mnt/user — contains the source
ok('a destination that would contain the source is refused', $r === '' && $err !== '', $err);

@exec('rm -rf '.escapeshellarg($destHold));
mkdir($destHold, 0755, true);
file_put_contents($destHold.'/something', 'already here');
$err = '';
$r = staxx_relocate_refuse($destHold, $err);
ok('a destination that already holds something is refused', $r === '' && $err !== '', $err);

// A good destination is accepted, and the check runs cleanly against a path
// that has not been created yet at all.
$err = '';
$r = staxx_relocate_refuse($destOK, $err);
ok('a clean, empty destination is accepted', $r === $destOK && $err === '', $err);

/* ------------------------------------- 2. an unreadable/missing source ---- */

rename($source, $source.'.aside');
clearstatcache(true, $source); // rename() leaves the old path's is_dir() cached stale otherwise
$err = '';
$manifest = staxx_relocate_scan($source, $err);
ok('a missing source is refused, not read as an empty success', $manifest === null && $err !== '', $err);
ok('the refusal says it could not look', stripos($err, 'could not') !== false, $err);
rename($source.'.aside', $source);
clearstatcache(true, $source);

/* ------------------------------------ 3. corrupted destination file ---- */

$err = '';
$manifest = staxx_relocate_scan($source, $err);
ok('the source scans cleanly', $manifest !== null, $err);

@exec('rm -rf '.escapeshellarg($destCorr));
$err = '';
ok('the copy to a scratch destination succeeds', staxx_relocate_copy_tree($source, $destCorr, $err), $err);

file_put_contents($destCorr.'/demo/notes.txt', 'CORRUPTED — this must never verify as a match');
$problems = staxx_relocate_verify($manifest, $destCorr);
ok('a corrupted file is caught by verification', $problems !== [] && preg_grep('/notes\.txt/', $problems));

// The response to a failed verify: remove the partial copy, leave the source alone.
staxx_relocate_cleanup($destCorr);
ok('the partial copy is fully removed after a failed verify', !is_dir($destCorr) && !file_exists($destCorr));
ok('the source is still byte-identical to the fixture',
   file_get_contents($source.'/demo/compose.yaml') === $composeFixture
   && file_get_contents($source.'/demo/notes.txt') === $notesFixture);
ok('the setting was never touched by a failed verify',
   strpos((string)@file_get_contents($cfgFile), 'STACK_ROOT="'.$source.'"') !== false);

/* -------------------------------------- 4. a missing destination file ---- */

@exec('rm -rf '.escapeshellarg($destMiss));
$err = '';
ok('the copy to a second scratch destination succeeds', staxx_relocate_copy_tree($source, $destMiss, $err), $err);

unlink($destMiss.'/demo/notes.txt');
$problems = staxx_relocate_verify($manifest, $destMiss);
ok('a missing file is caught by verification', $problems !== [] && preg_grep('/notes\.txt/', $problems));

staxx_relocate_cleanup($destMiss);
ok('the partial copy is fully removed after a missing file', !is_dir($destMiss) && !file_exists($destMiss));
ok('the source is still complete after the missing-file case',
   is_link($source.'/demo/inside-link') && is_link($source.'/demo/outside-link')
   && file_get_contents($source.'/demo/notes.txt') === $notesFixture);

/* --------------------------------------- 4b. the trial run, before a copy ---- */

// This section once tried to make the trial fail by chmod'ing the destination
// to 0555. That does not work and cannot: everything here runs as root, and
// root ignores permission bits. So the "read-only" destination was perfectly
// writable, the trial passed, the copy ran, and a case whose whole claim was
// "the move stops" quietly proved the opposite while reporting a failure for
// unrelated reasons. A failure that cannot actually be caused is worse than no
// test, because it reads as coverage.
//
// A destination whose parent is a FILE fails for root too — mkdir cannot make
// a directory underneath something that is not one. That is the kind of
// failure worth testing: real, and unaffected by who is running.

$blocker = '/mnt/user/appdata/staxx-relocate-test-blocker';
@unlink($blocker);
file_put_contents($blocker, "not a directory
");
$destBlocked = $blocker.'/inside';

$log = [];
$err = '';
$moveOk = staxx_relocate_run($destBlocked, function (string $line) use (&$log) { $log[] = $line; }, $err);
ok('a destination that cannot be created stops the move', !$moveOk && $err !== '', $err);
ok('...before any copying, and the source is untouched',
   file_get_contents($source.'/demo/compose.yaml') === $composeFixture
   && file_get_contents($source.'/demo/notes.txt') === $notesFixture
   && is_link($source.'/demo/inside-link') && is_link($source.'/demo/outside-link'));
ok('...and the setting still names the throwaway source',
   strpos((string)@file_get_contents($cfgFile), 'STACK_ROOT="'.$source.'"') !== false);
@unlink($blocker);

// A manifest that names a file and then something inside it cannot be laid
// down: the placeholder for the parent is created as a file, so creating a
// child under it fails. Root cannot get around that either, which is why this
// is the case used to prove a placeholder failure is caught and reported by
// path.
@exec('rm -rf '.escapeshellarg($destCorr));
mkdir($destCorr, 0755, true);
$err = '';
$bad = ['a' => ['type' => 'file', 'size' => 0, 'sha256' => '', 'target' => ''],
        'a/b' => ['type' => 'file', 'size' => 0, 'sha256' => '', 'target' => '']];
$trialOk = staxx_relocate_trial($bad, $destCorr, $err);
ok('a placeholder that cannot be created fails the trial', !$trialOk && $err !== '', $err);
ok('...and the message names the path that could not be made',
   strpos($err, 'a/b') !== false, $err);
ok('the trial folder is gone after a failed trial, so the next attempt is not blocked',
   glob($destCorr.'/.staxx-relocate-trial-*') === []);
$err2 = '';
$r = staxx_relocate_refuse($destCorr, $err2);
ok('...proved by the destination being accepted again', $r === $destCorr && $err2 === '', $err2);
@exec('rm -rf '.escapeshellarg($destCorr));

// Two manifest entries differing only in case must be caught as a clash, not
// waved through. Proved on the flash drive rather than the array or a pool:
// xfs, btrfs and zfs all tell "Demo" and "demo" apart, so a real clash needs
// a filesystem that genuinely folds case, and the flash drive (vfat) is the
// only one on an Unraid box that does.
@exec('rm -rf '.escapeshellarg($destCase));
$caseManifest = [
  'Demo' => ['type' => 'dir',  'size' => 0, 'sha256' => '', 'target' => ''],
  'demo' => ['type' => 'file', 'size' => 0, 'sha256' => '', 'target' => ''],
];
$err = '';
$r = staxx_relocate_trial($caseManifest, $destCase, $err);
ok('two names differing only in case are reported as a clash, not a pass', $r === false && $err !== '', $err);
// The trial folder goes; the destination folder does NOT, and that is the
// point rather than an oversight. A failed trial only clears up after itself —
// whether the destination survives is the caller's decision, because only the
// caller knows whether it created it or somebody else did. Removing a folder
// the person made themselves, to report a failure that was ours, is not ours
// to do. Called here with no caller, so this test does its own tidying.
ok('a failed clash leaves no trial folder on the flash drive',
   glob($destCase.'/.staxx-relocate-trial-*') === []);
@exec('rm -rf '.escapeshellarg($destCase));

// A symlink is proved creatable at the new location by the trial run on its
// own, and the trial folder it used is gone afterwards either way.
@exec('rm -rf '.escapeshellarg($destLink));
$linkManifest = [
  'demo'            => ['type' => 'dir',  'size' => 0, 'sha256' => '', 'target' => ''],
  'demo/target.txt' => ['type' => 'file', 'size' => 0, 'sha256' => '', 'target' => ''],
  'demo/a-link'     => ['type' => 'link', 'size' => 0, 'sha256' => '', 'target' => 'target.txt'],
];
$err = '';
$r = staxx_relocate_trial($linkManifest, $destLink, $err);
ok('a symlink in the manifest is proved creatable at the new location', $r === true, $err);
ok('the trial folder is gone from a destination it proved out',
   glob($destLink.'/.staxx-relocate-trial-*') === []);
@exec('rm -rf '.escapeshellarg($destLink));

/* ------------------------------------------------- 5. a successful move ---- */
// This is the only case that actually succeeds, and it consumes the source
// and switches the real config — every case above had to run first.

$log = [];
$err = '';
$moveOk = staxx_relocate_run($destOK, function (string $line) use (&$log) { $log[] = $line; }, $err);
ok('the move succeeds end to end', $moveOk, $err);
ok('the trial run happened and is reported in the log',
   (bool)preg_grep('/Testing whether the new location/', $log), implode(' | ', $log));
ok('no trial folder is left behind at the new location',
   glob($destOK.'/.staxx-relocate-trial-*') === []);

ok('the compose file, comment and anchor included, is byte-identical',
   @file_get_contents($destOK.'/demo/compose.yaml') === $composeFixture);
ok('the file the tool does not recognise travelled too',
   @file_get_contents($destOK.'/demo/notes.txt') === $notesFixture);
ok('the inside-pointing symlink is still a symlink, not a copy',
   is_link($destOK.'/demo/inside-link'));
ok('...pointing at exactly the same target text',
   @readlink($destOK.'/demo/inside-link') === 'notes.txt');
ok('the outside-pointing symlink is preserved, not followed',
   is_link($destOK.'/demo/outside-link') && @readlink($destOK.'/demo/outside-link') === '/etc/hostname');
ok('the outside link is called out in the report',
   (bool)preg_grep('/outside-link.*outside the stacks folder/', $log), implode(' | ', $log));

ok('the old stacks folder is gone', !is_dir($source) && !file_exists($source));
ok('the setting on disk now names the new location',
   strpos((string)@file_get_contents($cfgFile), 'STACK_ROOT="'.$destOK.'"') !== false);

/* ------------------------------------- 6. the job starter's own refusal ---- */
// A synchronous check only — following a detached job to its finish needs a
// live poll loop this test does not attempt.
//
// READ THIS BEFORE CHANGING THE DESTINATION BELOW. staxx_relocate_start() runs
// a REAL move in a REAL detached process when it is not refused, and that
// process is a fresh PHP run: it re-reads the config from disk and gets the
// SERVER'S OWN stack root, not the throwaway one this file seeded into a
// memoised staxx_cfg(). It also outlives this script, so it keeps going after
// the shutdown handler has put the config back and swept the scratch folders
// away.
//
// This case once passed a destination whose refusal depended on an EARLIER
// case having succeeded. When that earlier case failed, the refusal did not
// happen, a real job started, and it moved the server's own stacks. So the
// destination here must be refused STRUCTURALLY — for a reason that cannot
// stop being true. The source folder is exactly that: a destination equal to
// the current stacks folder is refused by the first rule in
// staxx_relocate_refuse(), whatever else has or has not happened above.
$err = '';
$job = staxx_relocate_start($source, $err);
ok('starting a job refuses a bad destination before anything runs', $job === '' && $err !== '', $err);
ok('...and refused it for being the source, the one reason that cannot lapse',
   strpos($err, 'current stacks folder') !== false, $err);

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
