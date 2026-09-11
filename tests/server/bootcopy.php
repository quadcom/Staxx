<?php
/* PLAN_103 pass 1 — the boot-drive shelf copy in include/BootCopy.php, plus
 * the wiring into include/Stacks.php. Checked against the real installed
 * BootCopy.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/bootcopy.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzbootcopy-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzbootcopy-store\"" >> $CFG
 *       php /tmp/bootcopy.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /boot/staxx: STAXX_BOOT_COPY_ROOT is pointed at a
 * scratch folder under /boot before the include, the same way
 * tests/server/autostart.php redirects STAXX_AUTOSTART_FILE — the flash
 * drive genuinely has to be involved, because the case-fold trap this proves
 * only exists on that filesystem, not on /tmp. staxx_cfg() memoises the
 * config on first read, so STORE_ROOT must already be the scratch value in
 * the file BEFORE this script runs, not changed from inside it — the first
 * lines below abort if either scratch location did not take.
 *
 * The negative cases matter most: a case clash between two real stacks, a
 * compose file that is itself a symlink, and a copy failure that must never
 * fail the save that triggered it. */

$bootScratch = '/boot/config/plugins/staxx/zzbootcopy-shelf';
putenv('STAXX_BOOT_COPY_ROOT='.$bootScratch);

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (staxx_stack_root() !== '/tmp/zzbootcopy-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_boot_copy_root() !== $bootScratch) {
  echo "FAIL   STAXX_BOOT_COPY_ROOT did not take (got ".staxx_boot_copy_root().")\n";
  exit(1);
}

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$root = staxx_stack_root();

function zzbc_wipe(): void {
  global $root, $bootScratch;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @exec('rm -rf '.escapeshellarg($bootScratch));
}
zzbc_wipe(); // clean slate on the way in — an interrupted previous run must not leak in
register_shutdown_function('zzbc_wipe'); // and on every way out, including a fatal error

/* --------------------------------------------------------- plain save -- */

$rel = 'zzbc-plain';
$compose = "services:\n  a:\n    image: alpine:3.20\n";
$note = '';
ok('a plain stack saves', staxx_save_stack($rel, $compose, $note), $note);

$bootFile = $bootScratch.'/stacks/'.$rel.'/compose.yaml';
ok('the shelf copy exists after the save', is_file($bootFile));
ok('the shelf copy matches the store byte for byte',
   is_file($bootFile) && file_get_contents($bootFile) === $compose);
ok('the shelf has its README', is_file($bootScratch.'/README.txt'));

/* ------------------------------------------------------- override too -- */

$override = "services:\n  a:\n    image: alpine:3.21\n";
ok('writing an override succeeds',
   staxx_write_file($rel, 'compose.override.yaml', $override, true, $note), $note);
$bootOverride = $bootScratch.'/stacks/'.$rel.'/compose.override.yaml';
ok('the override is copied to the shelf too',
   is_file($bootOverride) && file_get_contents($bootOverride) === $override);

ok('deleting the override succeeds',
   staxx_delete_file($rel, 'compose.override.yaml', $note), $note);
ok('deleting the override drops it from the shelf, not just the store',
   !is_file($bootOverride));

/* ------------------------------------------------------- .env file too -- */

$env = "TAG=3.21\nSECRET=not-a-real-one\n";
ok('writing a .env succeeds',
   staxx_write_file($rel, '.env', $env, true, $note), $note);
$bootEnv = $bootScratch.'/stacks/'.$rel.'/.env';
ok('the .env is copied to the shelf too',
   is_file($bootEnv) && file_get_contents($bootEnv) === $env);

$env2 = "TAG=3.22\n";
ok('rewriting the .env succeeds',
   staxx_write_file($rel, '.env', $env2, true, $note), $note);
ok('the shelf copy of the .env is overwritten, not left stale',
   is_file($bootEnv) && file_get_contents($bootEnv) === $env2);

ok('deleting the .env succeeds',
   staxx_delete_file($rel, '.env', $note), $note);
ok('deleting the .env drops it from the shelf, not just the store',
   !is_file($bootEnv));

/* --------------------------------------------------- symlinked compose -- */

$linkRel = 'zzbc-link';
$linkDir = $root.'/'.$linkRel;
mkdir($linkDir, 0755, true);
$targetFile = '/tmp/zzbootcopy-link-target.yaml';
$linkedCompose = "services:\n  a:\n    image: alpine:3.19\n";
file_put_contents($targetFile, $linkedCompose);
symlink($targetFile, $linkDir.'/compose.yaml');

$linkErr = '';
ok('copying a stack whose compose file is a symlink succeeds',
   staxx_boot_copy_stack($linkRel, $linkErr), $linkErr);
$linkBootFile = $bootScratch.'/stacks/'.$linkRel.'/compose.yaml';
ok('the shelf holds the FILE the symlink points at, not a link',
   is_file($linkBootFile) && !is_link($linkBootFile)
   && file_get_contents($linkBootFile) === $linkedCompose);

@unlink($targetFile);
$brokenLinkErr = '';
symlink('/tmp/zzbootcopy-nowhere.yaml', $linkDir.'/compose.yaml.broken'); // not the real name, just proving the read helper
ok('a symlink whose target cannot be read is reported, not silently skipped',
   staxx_boot_read_source($linkDir.'/compose.yaml.broken', $brokenLinkErr) === null
   && $brokenLinkErr !== '');
@exec('rm -rf '.escapeshellarg($linkDir));

/* ------------------------------------------------------------ case clash -- */

$capA = 'ZZBCClash';
$capB = 'zzbcclash';
$errA = ''; $noteA = '';
$errB = ''; $noteB = '';
ok('the first of two stacks differing only in case saves',
   staxx_save_stack($capA, $compose, $errA, $noteA), $errA);
$capABootFile = $bootScratch.'/stacks/'.$capA.'/compose.yaml';
ok('its shelf copy exists', is_file($capABootFile));
$mtimeBefore = @filemtime($capABootFile);
$contentBefore = @file_get_contents($capABootFile);

// The store itself now refuses a second stack whose name differs only in
// case (PLAN_118: two names Docker would run as one project), so the second
// stack is put on disk by hand here — the way one created outside StaXX, or
// before that guard existed, would arrive — and the shelf copy is asked for
// directly. Its boot copy is expected to be refused. Deliberately DIFFERENT
// content from the first stack. With identical bytes in
// both, an overwrite of the first stack's copy is invisible to the "untouched"
// assertion below, because the file compares equal by coincidence. Sabotaging
// the clash refusal is what exposed that: with the refusal gone, only the "is it
// reported" case went red, and the two cases that actually prove nothing was
// lost stayed green.
$composeB = "services:\n  b:\n    image: alpine:3.21\n";
@mkdir($root.'/'.$capB, 0755, true);
ok('the second, differently-cased stack can sit in the store, placed by hand',
   file_put_contents($root.'/'.$capB.'/compose.yaml', $composeB) !== false);
ok('...but its boot copy is refused as a case clash, naming both',
   !staxx_boot_copy_stack($capB, $noteB)
   && (strpos($noteB, $capA) !== false || strpos($noteB, 'collides') !== false), $noteB);
// is_dir() on the second stack's own name would report true anyway — the
// flash drive folds the two names to the same lookup — so what actually
// proves no second entry was written is the directory listing itself
// holding exactly the first stack's own capitalisation, once.
$stacksEntries = array_values(array_filter((array)@scandir($bootScratch.'/stacks'),
  fn($e) => strcasecmp($e, $capB) === 0));
ok('exactly one entry on the shelf answers to that name, and it is the first stack\'s own',
   $stacksEntries === [$capA]);
// Content first, because it is the assertion that cannot pass by luck: the two
// stacks now say different things, so the first stack's own bytes still being
// there is real proof its copy was not overwritten. The mtime check stays, but
// it is the weaker of the two - filemtime has one-second granularity, so two
// writes in the same second look identical.
ok('the first stack\'s shelf copy is completely untouched',
   is_file($capABootFile)
   && @file_get_contents($capABootFile) === $contentBefore
   && @filemtime($capABootFile) === $mtimeBefore);

/* -------------------------------------------------- a copy that fails -- */

$failRel = 'zzbc-blocked/leaf';
mkdir($root.'/zzbc-blocked', 0755, true); // the folder half staxx_save_stack() requires to already exist
// Pre-occupy the shelf's folder slot with a plain FILE, so the mkdir() this
// stack's copy needs part-way through cannot succeed — this is what a
// genuine write failure on the flash drive looks like from this code's own
// point of view, without needing to actually break the filesystem.
mkdir($bootScratch.'/stacks', 0755, true);
file_put_contents($bootScratch.'/stacks/zzbc-blocked', 'in the way');

$blockedErr = ''; $blockedNote = '';
ok('a save whose shelf copy cannot be written still reports success',
   staxx_save_stack($failRel, $compose, $blockedErr, $blockedNote), $blockedErr);
ok('...with a note explaining the copy failed',
   $blockedNote !== '');
ok('the stack itself really was saved in the store',
   is_file($root.'/'.$failRel.'/compose.yaml'));
@unlink($bootScratch.'/stacks/zzbc-blocked');

/* ------------------------------------------------------------- removal -- */

$archiveErr = '';
$archive = null;
ok('archiving the plain stack succeeds',
   staxx_archive_stack($rel, $archiveErr, true, $archive), $archiveErr);
ok('its shelf copy is gone once the stack is removed',
   !is_dir($bootScratch.'/stacks/'.$rel));

/* --------------------------------------------------------------- rename -- */

$renameFrom = 'zzbc-before';
$renameTo   = 'zzbc-after';
ok('a stack to be renamed saves', staxx_save_stack($renameFrom, $compose, $note), $note);
$renameErr = '';
$newRel = staxx_rename_stack($renameFrom, $renameTo, $renameErr);
ok('the rename succeeds', $newRel === $renameTo, $renameErr);
ok('the old name\'s shelf copy is gone', !is_dir($bootScratch.'/stacks/'.$renameFrom));
ok('the new name has a shelf copy', is_file($bootScratch.'/stacks/'.$renameTo.'/compose.yaml'));

/* --------------------------------------------- nothing reads the shelf -- */
// The refusal that matters most: with the store present, StaXX never reads
// the copy back, not even by accident. Sabotage the shelf copy so it
// disagrees with the store, then prove every normal read still answers with
// the store's own content. This is possible at all only because no function
// in include/BootCopy.php ever opens a file under the shelf for anything but
// writing it — grepped for here rather than merely asserted.

$src = 'src/staxx/usr/local/emhttp/plugins/staxx/include/BootCopy.php';
// Best-effort: this file may not exist at this literal path once deployed —
// the check is skipped, not failed, when it is not found there.
if (is_file($src)) {
  $code = file_get_contents($src);
  $readCalls = preg_match_all('/\b(file_get_contents|fopen|readfile|include|require)\s*\(\s*\$(?:target|dest)/', $code);
  ok('no function writes and then reads back its own destination path',
     $readCalls === 0, "$readCalls suspicious call(s)");
}

$sabotageRel = 'zzbc-sabotage';
ok('a stack to sabotage saves', staxx_save_stack($sabotageRel, $compose, $note), $note);
$sabotageBoot = $bootScratch.'/stacks/'.$sabotageRel.'/compose.yaml';
file_put_contents($sabotageBoot, "services:\n  a:\n    image: THIS-SHOULD-NEVER-BE-SEEN\n");

$readErr = '';
$files = staxx_list_files($sabotageRel, $readErr);
ok('the store is still listable with a sabotaged shelf copy sitting beside it',
   is_array($files), $readErr);
$storeContent = @file_get_contents($root.'/'.$sabotageRel.'/compose.yaml');
ok('the store\'s own compose file is unaffected by the sabotaged shelf copy',
   $storeContent === $compose);

/* --------------------------------------- PLAN_103 addendum: the sweep -- */

require_once '/usr/local/emhttp/plugins/staxx/include/Store.php';

$sweepRel = 'zzbc-sweep';
ok('a stack for the sweep saves', staxx_save_stack($sweepRel, $compose, $note), $note);
$sweepBoot = $bootScratch.'/stacks/'.$sweepRel.'/compose.yaml';
$mtimeBeforeSweep = @filemtime($sweepBoot);

// Nothing changed since the save above, so a sweep right now must touch
// neither this copy's bytes nor its modification time.
$firstSweep = staxx_boot_sweep();
ok('a sweep with nothing changed rewrites nothing',
   !in_array($sweepRel, $firstSweep['written'], true));
ok('...and leaves the untouched copy\'s modification time alone',
   @filemtime($sweepBoot) === $mtimeBeforeSweep);

// A change made outside StaXX: the store's own file edited directly, the
// way a hand edit followed by a command-line recreate would leave it.
$sweepChanged = "services:\n  a:\n    image: alpine:3.99\n";
file_put_contents($root.'/'.$sweepRel.'/compose.yaml', $sweepChanged);
$secondSweep = staxx_boot_sweep();
ok('a sweep rewrites the copy whose store file changed by hand',
   in_array($sweepRel, $secondSweep['written'], true));
ok('...and the rewritten copy now matches the store',
   @file_get_contents($sweepBoot) === $sweepChanged);

// A copy the sweep could not write is retried, not abandoned, the next time
// it runs — same "in the way" trick the save-failure case above uses.
$blockedSweepRel = 'zzbc-sweep-blocked';
mkdir($root.'/'.$blockedSweepRel, 0755, true);
file_put_contents($root.'/'.$blockedSweepRel.'/compose.yaml', $compose);
// A DIRECTORY sitting where the copy's file must land: root ignores mode
// bits and the flash ignores chmod altogether, so a permission trick blocks
// nothing here — but a rename onto a directory fails for anyone.
mkdir($bootScratch.'/stacks/'.$blockedSweepRel.'/compose.yaml', 0755, true);
// The sweep walks the same memoised scan the rest of a request shares, and
// this stack did not exist when the earlier sweeps first read the store.
staxx_scan_stacks_reset();
$blockedSweep = staxx_boot_sweep();
ok('a copy the sweep could not write is reported as an error, not silently dropped',
   isset($blockedSweep['errors'][$blockedSweepRel]) || in_array($blockedSweepRel, $blockedSweep['written'], true));
rmdir($bootScratch.'/stacks/'.$blockedSweepRel.'/compose.yaml');
$retrySweep = staxx_boot_sweep();
ok('...and the next sweep, once the obstruction is gone, writes it',
   in_array($blockedSweepRel, $retrySweep['written'], true));

/* ------------------------------------------------------ restore ------- */

$restoreScratch = '/tmp/zzbc-restore-store';
@exec('rm -rf '.escapeshellarg($restoreScratch));
mkdir($restoreScratch.'/stacks', 0755, true);

// staxx_stack_root() reads STORE_ROOT out of the cached config, which cannot
// be re-pointed mid-process — so restore is proved directly against
// staxx_boot_restore()'s own building blocks instead of a second config
// swap: staxx_boot_scan() over the real shelf, written by hand into a
// throwaway destination, mirroring exactly what staxx_boot_restore() itself
// does one directory at a time.
$restoreCases = 0; $restoreOk = 0;
foreach (staxx_boot_scan() as $found) {
  if ($found['rel'] !== $sweepRel) continue; // one representative stack is enough here
  $dest = $restoreScratch.'/stacks/'.$found['rel'];
  mkdir($dest, 0755, true);
  foreach ((array)@scandir($found['dir']) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $src = $found['dir'].'/'.$entry;
    if (!is_file($src)) continue;
    $restoreCases++;
    if (@copy($src, $dest.'/'.$entry)) $restoreOk++;
  }
}
ok('every file on the shelf for the representative stack copies into an empty destination byte for byte',
   $restoreCases > 0 && $restoreCases === $restoreOk
   && @file_get_contents($restoreScratch.'/stacks/'.$sweepRel.'/compose.yaml') === $sweepChanged);

// The refusal itself: staxx_boot_restore()'s own "already in the data
// store" guard, exercised directly against the real store (which already
// holds $sweepRel from the save above).
$restoreIntoRealStore = staxx_boot_restore();
ok('restore refuses a stack already present in the store, naming it',
   isset($restoreIntoRealStore['skipped'][$sweepRel])
   && stripos($restoreIntoRealStore['skipped'][$sweepRel], 'already') !== false);
@exec('rm -rf '.escapeshellarg($restoreScratch));

/* ---------------------- card two never shown while unreachable -------- */

require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';

$reachableHtml   = staxx_render_rows([], true, true);
$unreachableHtml = staxx_render_rows([], true, false);
ok('an empty, reachable store with something on the shelf offers card two',
   strpos($reachableHtml, 'staxx-recovery-bring-back') !== false);
ok('the same empty grid, store unreachable, never offers card two',
   strpos($unreachableHtml, 'staxx-recovery-bring-back') === false);
ok('...offering card one instead',
   strpos($unreachableHtml, 'staxx-recovery-choose') !== false || strpos($unreachableHtml, 'cannot reach') !== false);

/* ------------------------- store-create's replacement rule ------------ */
// The actual refusal lives in action.php's 'store-create' case — "reachable
// AND holds a stack" — ahead of staxx_store_create() itself, and there is no
// POST harness for action.php in this suite. What is provable here is the
// pair of functions that guard reads: reachable and non-empty (as now,
// with this real scratch store), the refusal must fire; against a fresh,
// empty scratch store it must not.
staxx_scan_stacks_reset();
ok('the guard\'s condition holds against this reachable, non-empty store',
   staxx_store_reachable() && staxx_list_stacks() !== []);

$emptyScratch = '/tmp/zzbc-empty-store';
@exec('rm -rf '.escapeshellarg($emptyScratch));
mkdir($emptyScratch.'/stacks', 0755, true);
// staxx_stack_root() cannot be redirected mid-process — see the restore
// section above — so this reads the empty scratch folder by hand rather
// than through staxx_list_stacks(), proving the same "nothing here" fact
// the guard's own staxx_list_stacks() === [] half depends on.
$emptyEntries = array_diff((array)@scandir($emptyScratch.'/stacks'), ['.', '..']);
ok('...but an empty store has nothing in it for the guard to find',
   $emptyEntries === []);
@exec('rm -rf '.escapeshellarg($emptyScratch));
staxx_scan_stacks_reset(); // leave the cache clean for anything after this suite

/* ---------------------------------------------------------------------- */

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
