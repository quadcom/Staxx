<?php
/* PLAN_97 Phase 3 — relocation moves the whole data store, not the stacks
 * tree alone. Checked against the real installed Relocate.php.
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
 * Unlike most tests here, the throwaway store cannot live under /tmp:
 * staxx_relocate_refuse() reuses the real STORE_ROOT path validator, which only
 * ever accepts a path under /mnt/ (a real share or disk) or under the plugin's
 * own config folder — never /tmp, which is a RAM disk the validator exists
 * partly to catch. So the throwaway source and every throwaway destination
 * below live under /mnt/user/appdata/, each in its own clearly-named scratch
 * folder, removed again on every exit path. They sit INSIDE an existing share
 * rather than directly under /mnt/user, because a directory made straight
 * inside /mnt/user is a whole new top-level share with whatever defaults
 * happen to apply — staxx_folder_create() refuses to do that for exactly this
 * reason, and a test has no more business inventing six shares than the
 * plugin does. This NEVER touches the real store, or any real appdata. One
 * case is the deliberate exception: proving the trial run catches a genuine
 * case clash needs a filesystem that folds case, which no array disk or pool
 * does, so that one scratch folder lives on the flash drive instead — created
 * empty, removed within the same call, and removed again defensively straight
 * after.
 *
 * staxx_cfg() memoises the config on first read for the life of the process, so
 * STORE_ROOT is pointed at its throwaway value once, before anything here
 * calls staxx_store_root() for the first time, and is never changed again
 * mid-run — exactly the constraint tests/server/moves.php's own comment
 * explains. That is also why the one case that actually succeeds (and so
 * switches STORE_ROOT for real, and deletes the source) has to run LAST: every
 * case before it must leave the throwaway source and the config alone.
 *
 * PLAN_97 Phase 3: the source relocation moves is now the whole data store —
 * staxx_relocate_refuse() and staxx_relocate_run() both read
 * staxx_store_root(), and the fixture below is a whole store: a "stacks"
 * folder holding two stacks (one carrying its own hidden .staxx record
 * folder), an "archives" folder holding a file standing in for a removed
 * stack's zip, and a "config" folder holding a note. Phase 1's blanket
 * refusal is gone, so a clean destination is accepted rather than turned
 * away outright, and the destination checks that used to compare against the
 * stacks folder alone now compare against the store as a whole — a
 * destination inside "stacks" or inside "archives" is refused the same way a
 * destination that IS the store is. */

require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$cfgFile     = '/boot/config/plugins/staxx/staxx.cfg';
$store       = '/mnt/user/appdata/staxx-relocate-test-src-store'; // staxx_store_root()
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
$notesFixture   = "a file the tool does not recognise\n";
$secondCompose  = "services:\n  app:\n    image: alpine:3.20\n";
$recordFixture  = "{\"created\":\"a stand-in for the hidden record a real stack keeps\"}\n";
$archiveFixture = str_repeat("PK\x03\x04 not a real zip, just bytes standing in for one\n", 64);
$configFixture  = "a stand-in for the note StaXX writes into a store's config folder\n";

$cfgBackup = @file_get_contents($cfgFile);

register_shutdown_function(function () use ($cfgFile, $cfgBackup, $store, $allDest, $destCase) {
  if ($cfgBackup === false) { @unlink($cfgFile); } else { @file_put_contents($cfgFile, $cfgBackup); }
  @exec('rm -rf '.escapeshellarg($store));
  @exec('rm -rf '.escapeshellarg($store.'.aside'));
  foreach ($allDest as $d) { @chmod($d, 0755); @exec('rm -rf '.escapeshellarg($d)); }
  @unlink('/mnt/user/appdata/staxx-relocate-test-blocker');
  @exec('rm -rf '.escapeshellarg($destCase));
  echo "config restored, scratch folders removed\n";
});

/* ---- point STORE_ROOT at a throwaway tree, before staxx_cfg() is ever asked ---- */
$cfgLines = $cfgBackup !== false ? preg_split('/\r?\n/', $cfgBackup) : [];
$cfgLines = array_values(array_filter($cfgLines, fn($l) =>
  strpos(trim((string)$l), 'STORE_ROOT=') !== 0
));
$cfgLines[] = 'STORE_ROOT="'.$store.'"';
@file_put_contents($cfgFile, implode("\n", $cfgLines)."\n");

ok('the throwaway store root is in force',   staxx_store_root()   === $store,               staxx_store_root());
ok('the throwaway stack root derives from it',   staxx_stack_root()   === $store.'/stacks',   staxx_stack_root());
ok('the throwaway archive root derives too', staxx_archive_root() === $store.'/archives',    staxx_archive_root());

// Not an assertion that is allowed to fail and carry on. Everything below
// moves and deletes whatever the store root points at, so if the seeding did
// not take, the next few hundred lines operate on the server's own store.
// Stop dead instead — a failed guard is not a reason to keep going, it is the
// reason the guard exists.
if (staxx_store_root() !== $store) {
  echo "
REFUSING TO RUN: the throwaway store root did not take effect, so these cases would
"
     . "act on the real data store (".staxx_store_root()."). Nothing was done.
";
  exit(1);
}

/** (Re)build the fixture store: a stacks folder with two stacks — one with a
 *  compose file carrying a comment and an anchor/alias, a file the tool has
 *  no opinion about, and two symlinks (one inside the stack, one pointing
 *  outside the store entirely); the other with its own hidden .staxx record
 *  folder, the thing a walker that skips dot-entries would silently drop —
 *  an archives folder holding a file standing in for a removed stack's zip,
 *  the one copy of it that exists; and a config folder holding a note. */
function relocate_test_build_store(
  string $root, string $compose, string $notes, string $secondCompose,
  string $record, string $archive, string $configNote
): void {
  @exec('rm -rf '.escapeshellarg($root));

  $demo = $root.'/stacks/demo';
  mkdir($demo, 0755, true);
  file_put_contents($demo.'/compose.yaml', $compose);
  file_put_contents($demo.'/notes.txt', $notes);
  @symlink('notes.txt', $demo.'/inside-link');
  @symlink('/etc/hostname', $demo.'/outside-link');

  $second = $root.'/stacks/second';
  mkdir($second.'/'.STAXX_RECORD_DIR, 0755, true);
  file_put_contents($second.'/compose.yaml', $secondCompose);
  file_put_contents($second.'/'.STAXX_RECORD_DIR.'/record.json', $record);

  mkdir($root.'/archives', 0755, true);
  file_put_contents($root.'/archives/removed-stack.zip', $archive);

  mkdir($root.'/config', 0755, true);
  file_put_contents($root.'/config/README.txt', $configNote);
}

relocate_test_build_store(
  $store, $composeFixture, $notesFixture, $secondCompose, $recordFixture, $archiveFixture, $configFixture
);

/* ------------------------------------------------- 1. refused up front ---- */

$err = '';
$r = staxx_relocate_refuse($store, $err);
ok('a destination equal to the store itself is refused', $r === '' && $err !== '', $err);

$err = '';
$r = staxx_relocate_refuse($store.'/stacks/inside', $err);
ok('a destination inside the store\'s stacks folder is refused', $r === '' && $err !== '', $err);

$err = '';
$r = staxx_relocate_refuse($store.'/archives/inside', $err);
ok('a destination inside the store\'s archives folder is refused', $r === '' && $err !== '', $err);

$err = '';
$r = staxx_relocate_refuse(dirname($store), $err); // /mnt/user/appdata — contains the store
ok('a destination that would contain the store is refused', $r === '' && $err !== '', $err);

@exec('rm -rf '.escapeshellarg($destHold));
mkdir($destHold, 0755, true);
file_put_contents($destHold.'/something', 'already here');
$err = '';
$r = staxx_relocate_refuse($destHold, $err);
ok('a destination that already holds something is refused', $r === '' && $err !== '', $err);

// A good destination is accepted, and the check runs cleanly against a path
// that has not been created yet at all. This is the case that proves the
// Phase 1 blanket refusal is genuinely gone — a legitimate destination now
// gets a real answer rather than a fixed "not available yet" message.
$err = '';
$r = staxx_relocate_refuse($destOK, $err);
ok('a clean, empty destination is accepted', $r === $destOK && $err === '', $err);

/* ------------------------------------- 2. an unreadable/missing source ---- */

rename($store, $store.'.aside');
clearstatcache(true, $store); // rename() leaves the old path's is_dir() cached stale otherwise
$err = '';
$manifest = staxx_relocate_scan($store, $err);
ok('a missing source is refused, not read as an empty success', $manifest === null && $err !== '', $err);
ok('the refusal says it could not look', stripos($err, 'could not') !== false, $err);
rename($store.'.aside', $store);
clearstatcache(true, $store);

/* ------------------------------------ 3. corrupted destination file ---- */

$err = '';
$manifest = staxx_relocate_scan($store, $err);
ok('the whole store scans cleanly', $manifest !== null, $err);
ok('the manifest includes the archive', isset($manifest['archives/removed-stack.zip']));
ok('the manifest includes the config note', isset($manifest['config/README.txt']));
ok('the manifest includes the hidden record folder',
   isset($manifest['stacks/second/'.STAXX_RECORD_DIR]));
ok('...and the file inside it',
   isset($manifest['stacks/second/'.STAXX_RECORD_DIR.'/record.json']));

@exec('rm -rf '.escapeshellarg($destCorr));
$err = '';
$copyLog = [];
ok('the copy to a scratch destination succeeds',
   staxx_relocate_copy_tree($store, $destCorr, $err, function (string $l) use (&$copyLog) { $copyLog[] = $l; }),
   $err);

// The archive is the one file here that cannot be re-fetched if this went
// wrong, so it is the file corrupted here to prove verification catches it
// wherever in the store it sits, not only inside "stacks".
file_put_contents($destCorr.'/archives/removed-stack.zip', 'CORRUPTED — this must never verify as a match');
$problems = staxx_relocate_verify($manifest, $destCorr);
ok('a corrupted archive is caught by verification',
   $problems !== [] && preg_grep('#archives/removed-stack\.zip#', $problems));

// The response to a failed verify: remove the partial copy, leave the source alone.
staxx_relocate_cleanup($destCorr);
ok('the partial copy is fully removed after a failed verify', !is_dir($destCorr) && !file_exists($destCorr));
ok('the source stacks folder is still byte-identical to the fixture',
   file_get_contents($store.'/stacks/demo/compose.yaml') === $composeFixture
   && file_get_contents($store.'/stacks/demo/notes.txt') === $notesFixture);
ok('the source archive is still byte-identical to the fixture',
   file_get_contents($store.'/archives/removed-stack.zip') === $archiveFixture);
ok('the source config note is still byte-identical to the fixture',
   file_get_contents($store.'/config/README.txt') === $configFixture);
ok('the setting was never touched by a failed verify — it is written LAST, after verification',
   strpos((string)@file_get_contents($cfgFile), 'STORE_ROOT="'.$store.'"') !== false);

/* -------------------------------------- 4. a missing destination file ---- */

@exec('rm -rf '.escapeshellarg($destMiss));
$err = '';
ok('the copy to a second scratch destination succeeds', staxx_relocate_copy_tree($store, $destMiss, $err), $err);

unlink($destMiss.'/stacks/demo/notes.txt');
$problems = staxx_relocate_verify($manifest, $destMiss);
ok('a missing file is caught by verification', $problems !== [] && preg_grep('/notes\.txt/', $problems));

staxx_relocate_cleanup($destMiss);
ok('the partial copy is fully removed after a missing file', !is_dir($destMiss) && !file_exists($destMiss));
ok('the source is still complete after the missing-file case',
   is_link($store.'/stacks/demo/inside-link') && is_link($store.'/stacks/demo/outside-link')
   && file_get_contents($store.'/stacks/demo/notes.txt') === $notesFixture
   && is_dir($store.'/stacks/second/'.STAXX_RECORD_DIR));

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
ok('...leaving nothing behind, since it did not exist before either', !is_dir($destBlocked));
ok('...before any copying, and the source is untouched',
   file_get_contents($store.'/stacks/demo/compose.yaml') === $composeFixture
   && file_get_contents($store.'/stacks/demo/notes.txt') === $notesFixture
   && is_link($store.'/stacks/demo/inside-link') && is_link($store.'/stacks/demo/outside-link')
   && file_get_contents($store.'/archives/removed-stack.zip') === $archiveFixture);
ok('...and the setting still names the throwaway source',
   strpos((string)@file_get_contents($cfgFile), 'STORE_ROOT="'.$store.'"') !== false);
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
ok('...and the destination folder itself, which existed before the failed trial, is not removed',
   is_dir($destCorr));
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
mkdir($destCase, 0755, true); // exists beforehand, empty — must survive a failed trial untouched
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
ok('...and the pre-existing destination folder is still there',
   is_dir($destCase));
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
   @file_get_contents($destOK.'/stacks/demo/compose.yaml') === $composeFixture);
ok('the file the tool does not recognise travelled too',
   @file_get_contents($destOK.'/stacks/demo/notes.txt') === $notesFixture);
ok('the inside-pointing symlink is still a symlink, not a copy',
   is_link($destOK.'/stacks/demo/inside-link'));
ok('...pointing at exactly the same target text',
   @readlink($destOK.'/stacks/demo/inside-link') === 'notes.txt');
ok('the outside-pointing symlink is preserved, not followed',
   is_link($destOK.'/stacks/demo/outside-link') && @readlink($destOK.'/stacks/demo/outside-link') === '/etc/hostname');
ok('the outside link is called out in the report',
   /* Matched on the shape of the note rather than its exact words: the link
    * is named, and it is said to point out of the store. The wording moved
    * from "the stacks folder" to "the data store" when relocation started
    * moving the whole store, and pinning the old phrase here only proved the
    * test had not been read since. */
   (bool)preg_grep('/outside-link.*outside the data store/', $log), implode(' | ', $log));

// The hidden record folder is the thing most likely to be silently skipped
// by a walker that treats a dot-prefixed name as "nothing to see here" —
// checked explicitly rather than folded into a general "everything arrived".
ok('the second stack\'s hidden record folder arrived',
   is_dir($destOK.'/stacks/second/'.STAXX_RECORD_DIR));
ok('...with the record file inside it, byte-identical',
   @file_get_contents($destOK.'/stacks/second/'.STAXX_RECORD_DIR.'/record.json') === $recordFixture);

// The archive is the one copy of a removed stack, so it has to travel byte
// for byte or it has effectively been lost.
ok('the archive arrived, byte for byte',
   @file_get_contents($destOK.'/archives/removed-stack.zip') === $archiveFixture);

// The config folder — settings, icons, state — travels with the rest of the
// store, because it is part of the same tree now, not a separate location.
ok('the config note arrived, byte for byte',
   @file_get_contents($destOK.'/config/README.txt') === $configFixture);

ok('the old store is gone entirely', !is_dir($store) && !file_exists($store));

// The setting saved is the STORE ROOT, not a "stacks" subfolder of it — the
// fix this phase makes. Before it, the source moved was the stacks tree but
// the value saved was meant to be a store, so a fresh read would have derived
// "<saved>/stacks" and found nothing there. Now the whole store moves as one
// tree and the value saved names exactly where it landed.
ok('the setting on disk now names the new store location',
   strpos((string)@file_get_contents($cfgFile), 'STORE_ROOT="'.$destOK.'"') !== false);
ok('...and the stacks that actually moved really do sit at "<new store>/stacks"',
   is_dir($destOK.'/stacks/demo') && is_dir($destOK.'/stacks/second'));

/* ------------------------------------- 6. the job starter's own refusal ---- */
// A synchronous check only — following a detached job to its finish needs a
// live poll loop this test does not attempt.
//
// READ THIS BEFORE CHANGING THE DESTINATION BELOW. staxx_relocate_start() runs
// a REAL move in a REAL detached process when it is not refused, and that
// process is a fresh PHP run: it re-reads the config from disk and gets the
// SERVER'S OWN store root, not the throwaway one this file seeded into a
// memoised staxx_cfg(). It also outlives this script, so it keeps going after
// the shutdown handler has put the config back and swept the scratch folders
// away.
//
// This case once passed a destination whose refusal depended on an EARLIER
// case having succeeded. When that earlier case failed, the refusal did not
// happen, a real job started, and it moved the server's own store. So the
// destination here must be refused STRUCTURALLY — for a reason that cannot
// stop being true. The store folder itself is exactly that: a destination
// equal to the current store is refused by the first rule in
// staxx_relocate_refuse(), whatever else has or has not happened above.
$err = '';
$job = staxx_relocate_start($store, $err);
ok('starting a job refuses a bad destination before anything runs', $job === '' && $err !== '', $err);

/* ------------------------ a share the mover will drain is not a home -------
 * Found by hand: choosing a share whose policy moves its files to the array
 * was accepted with "this path can be used", because only the SUGGESTIONS
 * consulted the share's policy and a typed or browsed path skipped it. The
 * stacks would then have been carried off the pool afterwards, quietly.
 *
 * The rule itself is tested against fixture share files under /tmp — nothing
 * here reads or writes /boot/config/shares. The end-to-end case below then
 * proves the destination check actually calls it, using whichever real share
 * on this box is already set that way, and SKIPping where there is none. */

$sd = '/tmp/zzrel-shares';
@exec('rm -rf '.escapeshellarg($sd));
@mkdir($sd, 0777, true);
file_put_contents($sd.'/drains.cfg',  "shareUseCache=\"yes\"\nshareCachePool=\"cache-small\"\n");
file_put_contents($sd.'/pinned.cfg',  "shareUseCache=\"only\"\nshareCachePool=\"cache-small\"\n");
file_put_contents($sd.'/prefers.cfg', "shareUseCache=\"prefer\"\nshareCachePool=\"cache-small\"\n");
file_put_contents($sd.'/nokey.cfg',   "shareCachePool=\"cache-small\"\n");

ok('a share set to move onto the array is refused',
   staxx_share_drain_reason('drains', $sd) !== '');
/* The rule returns a CLAUSE and nothing more — every caller wraps it in its
 * own sentence, and one that carried its own advice produced "...choose a
 * different location.. Choose a folder inside a share...". So what is asserted
 * here is the shape of the clause, and separately that the caller which prints
 * it on its own does add the advice. */
$clause = staxx_share_drain_reason('drains', $sd);
ok('...and the reason names the mover and the array',
   strpos($clause, 'mover') !== false && strpos($clause, 'array') !== false, $clause);
ok('...and it is a clause, not a sentence with its own advice',
   $clause === '' || (strpos($clause, 'Shares page') === false
                      && substr($clause, -1) !== '.'
                      && $clause[0] === strtolower($clause[0])), $clause);
ok('...while the "Not offered" wording built from it does say what to change',
   strpos(staxx_storage_share_reason($sd, 'drains', 'cache-small'), 'Shares page') !== false,
   staxx_storage_share_reason($sd, 'drains', 'cache-small'));

// The three that must NOT be refused. "prefer" moves array -> pool, which is
// the opposite of the risk; "only" and "no" move nothing; and a file with no
// cache setting at all is never acted on, because the mover only ever reads
// what the file says.
ok('a share pinned with "only" is fine',   staxx_share_drain_reason('pinned',  $sd) === '');
ok('a share set to "prefer" is fine',      staxx_share_drain_reason('prefers', $sd) === '');
ok('a file with no cache setting is fine', staxx_share_drain_reason('nokey',   $sd) === '');
ok('a share with no settings file at all is fine — the mover never sees it',
   staxx_share_drain_reason('no-such-share-anywhere', $sd) === '');

@exec('rm -rf '.escapeshellarg($sd));

/* End to end: does the destination check actually ask? Read-only, and it uses
 * a real share rather than a fixture because that is the only way to prove the
 * wiring rather than the rule. */
$drainShare = '';
$drainPool  = '';
foreach ((array)@glob('/boot/config/shares/*.cfg') as $f) {
  $cfg = @parse_ini_file($f, false, INI_SCANNER_RAW);
  if (!is_array($cfg)) continue;
  if (trim((string)($cfg['shareUseCache'] ?? ''), '"') !== 'yes') continue;
  $pool = trim((string)($cfg['shareCachePool'] ?? ''), '"');
  if ($pool === '' || !is_dir('/mnt/'.$pool)) continue;
  $drainShare = basename($f, '.cfg');
  $drainPool  = $pool;
  break;
}

if ($drainShare === '') {
  echo "SKIP   end-to-end drain case — no share on this box is set to move onto the array\n";
} else {
  $err = '';
  $got = staxx_relocate_refuse('/mnt/'.$drainPool.'/'.$drainShare, $err);
  ok('a real drained share is refused as a destination ('.$drainShare.' on '.$drainPool.')',
     $got === '' && strpos($err, 'onto the array') !== false, $err);

  // The share-layer form of the same folder has to be refused too: the mover
  // reads the share's settings whichever path was used to reach it.
  $err = '';
  $got = staxx_relocate_refuse('/mnt/user/'.$drainShare.'/staxx-stacks', $err);
  ok('...and so is the /mnt/user form of it',
     $got === '' && strpos($err, 'onto the array') !== false, $err);
}


/* --------------------------- a share's own root is not the store root -----
 * Pointing the store root at a whole share makes every folder in that share
 * read as part of the store. Aimed at appdata, every container's config
 * folder would be swept in with it. It was accepted whenever the share
 * happened to be empty, which is exactly when nothing looks wrong yet.
 *
 * Read-only, and it uses real shares because the rule is about the shape of
 * the path rather than about any share's settings. appdata exists on every
 * Unraid box worth running this on; the case is skipped if it somehow does not.
 */
/* A share name that exists nowhere, so no OTHER refusal can reach it first.
 * A real share root is caught earlier by the rule about a destination that
 * would contain the current store — which is correct, and by this point in
 * the suite the store root has been repointed inside appdata, so aiming at a
 * real share here tested that older rule instead of this one. */
$fake = '/mnt/user/zzrel-fake-share';
$err = '';
$got = staxx_relocate_refuse($fake, $err);
ok('the whole of a share is refused as a store root',
   $got === '' && strpos($err, 'whole of the') !== false, $err);
ok('...and the refusal names the folder to use instead',
   strpos($err, $fake.'/'.STAXX_STORE_FOLDER) !== false, $err);

// The nested form of the same choice must still pass, or the rule above has
// blocked the location rather than corrected it.
$err = '';
staxx_relocate_refuse($fake.'/'.STAXX_STORE_FOLDER, $err);
ok('a folder INSIDE the share is not caught by that rule',
   strpos($err, 'whole of the') === false, $err);

/* A share named for StaXX is the exception: somebody who made one deliberately
 * means its root, and nesting staxx-stacks inside a share called staxx would be
 * silly. Tested through a path that need not exist — the rule is about the
 * name, and any other refusal for a fictional path is not this one. */
$err = '';
staxx_relocate_refuse('/mnt/cache-small/staxx', $err);
ok('a share named for StaXX may be used at its root',
   strpos($err, 'whole of the') === false, $err);


/* ------------- a drained share is reached through the share layer instead ---
 * StaXX prefers a direct pool path because it skips Unraid's share layer. But
 * a share the mover drains has its contents carried off to the array, and the
 * pool path then names a folder the data has left — the store would vanish
 * from the page. The share-layer form follows the data, so with the placement
 * rules set to get out of the way, that one case is rewritten rather than
 * allowed as it stands.
 *
 * Tested through the rule itself, using fixture share files under /tmp.
 *
 * HONEST GAP: the branch that CALLS this only runs with the placement rules
 * set to "open", and staxx_cfg() memoises on first read, so a suite cannot
 * switch modes partway through — it would need a sub-process, and pointing one
 * at a real config on a production server to prove a branch is not a trade
 * worth making. The guided half below is covered properly; the open half was
 * verified by hand on the box. Anything that changes that branch should be
 * re-checked the same way.
 */
$sd2 = '/tmp/zzrel-shares2';
@exec('rm -rf '.escapeshellarg($sd2));
@mkdir($sd2, 0777, true);
file_put_contents($sd2.'/drains.cfg', "shareUseCache=\"yes\"\nshareCachePool=\"cache-small\"\n");

// The rule reads the REAL shares folder, so the fixture above only documents
// the shape; the cases below use whatever this box actually has. A share set to
// drain is found the same way the end-to-end case above finds one.
$realDrain = '';
foreach ((array)@glob('/boot/config/shares/*.cfg') as $f) {
  $cfg = @parse_ini_file($f, false, INI_SCANNER_RAW);
  if (is_array($cfg) && trim((string)($cfg['shareUseCache'] ?? ''), '"') === 'yes') {
    $realDrain = basename($f, '.cfg');
    break;
  }
}
@exec('rm -rf '.escapeshellarg($sd2));

if ($realDrain === '') {
  echo "SKIP   share-layer swap cases — no share on this box is set to drain\n";
} else {
  $direct = '/mnt/cache-small/'.$realDrain.'/stacks';
  ok('a direct pool path in a drained share is swapped for the share-layer form',
     staxx_relocate_share_layer_form($direct) === '/mnt/user/'.$realDrain.'/stacks',
     staxx_relocate_share_layer_form($direct));

  // Applied twice must change nothing, because the background job re-checks
  // its own destination before touching anything.
  ok('the swap is idempotent',
     staxx_relocate_share_layer_form('/mnt/user/'.$realDrain.'/stacks') === '');

  ok('the share root itself swaps too, keeping the rest of the path empty',
     staxx_relocate_share_layer_form('/mnt/cache-small/'.$realDrain) === '/mnt/user/'.$realDrain);

  // And in guided mode — which is what this suite runs in, since that is the
  // default — the same path is refused outright rather than swapped.
  if (staxx_placement_guided()) {
    $err = '';
    ok('...while guided mode refuses it instead of swapping',
       staxx_relocate_refuse($direct, $err) === '' && strpos($err, 'onto the array') !== false, $err);
  } else {
    echo "SKIP   guided-refusal case — this box is set to open\n";
  }
}

// Nothing else is rewritten. An array disk keeps working when the mover runs;
// it is simply unprotected, so there is nothing for a swap to rescue, and
// silently moving somebody onto the share layer would be a change they did not
// ask for and would not benefit from.
ok('an array-disk path is never swapped',
   staxx_relocate_share_layer_form('/mnt/disk8/Client_Archives/stacks') === '');
ok('a path on a pool whose share stays put is never swapped',
   staxx_relocate_share_layer_form('/mnt/m2cache/appdata/staxx-stacks') === '');
ok('a path that is not /mnt/<x>/<share>/... is never swapped',
   staxx_relocate_share_layer_form('/mnt/cache-big') === ''
   && staxx_relocate_share_layer_form('/boot/config/plugins/staxx/stacks') === '');


echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
