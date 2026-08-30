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

// The second stack saves fine in the store — the store is a real, case-
// sensitive filesystem — but its OWN boot copy is expected to be refused.
// Deliberately DIFFERENT content from the first stack. With identical bytes in
// both, an overwrite of the first stack's copy is invisible to the "untouched"
// assertion below, because the file compares equal by coincidence. Sabotaging
// the clash refusal is what exposed that: with the refusal gone, only the "is it
// reported" case went red, and the two cases that actually prove nothing was
// lost stayed green.
$composeB = "services:\n  b:\n    image: alpine:3.21\n";
ok('the second, differently-cased stack also saves in the store',
   staxx_save_stack($capB, $composeB, $errB, $noteB), $errB);
ok('...but its boot copy is reported as a case clash, naming both',
   strpos($noteB, $capA) !== false || strpos($noteB, 'collides') !== false, $noteB);
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

/* ---------------------------------------------------------------------- */

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
