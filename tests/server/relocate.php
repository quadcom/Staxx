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
 * folder, or any real appdata.
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
$allDest  = [$destHold, $destCorr, $destMiss, $destOK];

$composeFixture = "# a hand-written comment that must survive\n"
                . "services:\n"
                . "  web: &base\n"
                . "    image: alpine:3.20 # inline comment\n"
                . "  worker:\n"
                . "    <<: *base\n";
$notesFixture = "a file the tool does not recognise\n";

$cfgBackup = @file_get_contents($cfgFile);

register_shutdown_function(function () use ($cfgFile, $cfgBackup, $source, $allDest) {
  if ($cfgBackup === false) { @unlink($cfgFile); } else { @file_put_contents($cfgFile, $cfgBackup); }
  @exec('rm -rf '.escapeshellarg($source));
  @exec('rm -rf '.escapeshellarg($source.'.aside'));
  foreach ($allDest as $d) @exec('rm -rf '.escapeshellarg($d));
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

/* ------------------------------------------------- 5. a successful move ---- */
// This is the only case that actually succeeds, and it consumes the source
// and switches the real config — every case above had to run first.

$log = [];
$err = '';
$moveOk = staxx_relocate_run($destOK, function (string $line) use (&$log) { $log[] = $line; }, $err);
ok('the move succeeds end to end', $moveOk, $err);

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
// A synchronous check only — actually following a detached job through to its
// STAXX_JOB_END sentinel needs a live poll loop this test does not attempt.

$err = '';
$job = staxx_relocate_start($destOK, $err); // $destOK now holds the moved stacks — "already holds something"
ok('starting a job refuses a bad destination before anything runs', $job === '' && $err !== '', $err);

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
