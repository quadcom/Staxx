<?php
/* PLAN_68 Part A — the per-stack record (edit history) in include/Record.php,
 * plus the two capture doors wired into Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b2-store, the same way tests/server/files.php does it —
 * never the real store, and never /boot, which is vfat and would make
 * several of the failure cases below pass for the wrong reason (a plain file
 * can't stand in for a directory there, and permissions are ignored).
 * staxx_cfg() memoises the first time it is read, so the key is seeded into
 * the config file BEFORE php runs, not changed from inside this script — by
 * the time this file's first line executes it is already too late to move
 * the store out from under it.
 *
 *     pscp tests/server/record.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/b2-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/b2-store\"" >> $CFG
 *       php /tmp/record.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzb2…", under the temporary stack root, and its
 * own zips under the temporary archive root. Cleans up on the way in too, so
 * a previous interrupted run cannot affect this one. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/b2-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_archive_root() !== '/tmp/b2-store/archives') {
  echo "FAIL   the temporary archive root is not in place (got ".staxx_archive_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
$archiveRoot = staxx_archive_root();

/* Clean slate — both on the way in (an interrupted previous run must not be
 * able to feed stale fixtures into this one) and, at the bottom, on the way
 * out. */
function b2_wipe(): void {
  global $root, $archiveRoot;
  @exec('rm -rf '.escapeshellarg($root).' '.escapeshellarg($archiveRoot));
  mkdir($root, 0755, true);
  mkdir($archiveRoot, 0755, true);
}
b2_wipe();

/* ------------------------------------------------------------- fixture -- */

$rel = 'zzb2test';
$dir = $root.'/'.$rel;
mkdir($dir, 0755, true);
$compose = "services:\n  a:\n    image: alpine:3.20\n";
file_put_contents($dir.'/compose.yaml', $compose);

function b2_reset_stack(string $dir, string $compose): void {
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $compose);
}

$note = '';

/* ------------------------------------------------ no .staxx directory -- */
// The default state of every stack today, and the case that proves nothing
// in the plugin requires the record to exist.

ok('record dir path is inside the stack, not created by asking for it',
   !is_dir(staxx_record_dir($rel)));
ok('reading with no record gives an empty index', staxx_record_read($rel) === []);
ok('listing with no record gives an empty list', staxx_record_list($rel) === []);
ok('getting from no record gives null', staxx_record_get($rel, 1) === null);
ok('the stack is still fully readable', is_array(staxx_list_files($rel, $note)), $note);
ok('the stack is still fully saveable',
   staxx_save_stack($rel, $compose, $note), $note);

/* -------------------------------------------------- truncated record.json -- */

$recDir = staxx_record_dir($rel);
mkdir($recDir, 0755, true);
file_put_contents($recDir.'/record.json', '{"versions": [ { "n": 1, "at"');

ok('a truncated index reads as empty, not a crash', staxx_record_read($rel) === []);
ok('listing still works with a truncated index', staxx_record_list($rel) === []);
ok('the stack still lists', is_array(staxx_list_files($rel, $note)), $note);
ok('the stack still saves', staxx_save_stack($rel, $compose, $note), $note);

/* -------------------------------------------- valid JSON, wrong shape -- */

file_put_contents($recDir.'/record.json', json_encode(['hello' => 'world', 'versions' => 'nope']));

ok('valid JSON of the wrong shape reads as empty', staxx_record_read($rel) === []);
ok('listing tolerates the wrong shape', staxx_record_list($rel) === []);
ok('the stack still saves with a wrongly-shaped index', staxx_save_stack($rel, $compose, $note), $note);

/* -------------------------------------- index names a missing history file -- */

@exec('rm -rf '.escapeshellarg($recDir));
mkdir($recDir, 0755, true);
mkdir($recDir.'/history', 0755, true);
$fakeHash = hash('sha256', 'never actually written');
file_put_contents($recDir.'/record.json', json_encode(['next' => 2, 'versions' => [
  ['n' => 1, 'at' => time(), 'size' => 10, 'hash' => $fakeHash, 'file' => '0001.yaml', 'name' => ''],
]]));

ok('listing tolerates an index entry with no backing file',
   is_array(staxx_record_list($rel)));
ok('getting a version whose file is missing returns null',
   staxx_record_get($rel, 1) === null);
ok('the stack still saves with a dangling index entry', staxx_save_stack($rel, $compose, $note), $note);

/* -------------------------------------- history file, hash no longer matches -- */

@exec('rm -rf '.escapeshellarg($recDir));
mkdir($recDir, 0755, true);
mkdir($recDir.'/history', 0755, true);
file_put_contents($recDir.'/history/0001.yaml', 'this is not what the hash says');
file_put_contents($recDir.'/record.json', json_encode(['next' => 2, 'versions' => [
  ['n' => 1, 'at' => time(), 'size' => 4, 'hash' => hash('sha256', 'xxxx'), 'file' => '0001.yaml', 'name' => ''],
]]));

ok('a version whose stored bytes no longer match its hash returns null, not corrupted bytes',
   staxx_record_get($rel, 1) === null);
ok('the stack still saves with a hash mismatch present', staxx_save_stack($rel, $compose, $note), $note);

/* ---------------------------------- .staxx replaced by a plain file -- */

@exec('rm -rf '.escapeshellarg($recDir));
file_put_contents($recDir, 'not a directory at all');

ok('a plain file where the record directory should be reads as empty',
   staxx_record_read($rel) === []);
ok('listing tolerates the record slot being a plain file', staxx_record_list($rel) === []);
ok('the stack still lists with the record slot occupied by a file',
   is_array(staxx_list_files($rel, $note)), $note);
ok('the stack still saves with the record slot occupied by a file',
   staxx_save_stack($rel, $compose, $note), $note);
@unlink($recDir);

/* ------------------------------------------------------------ capture -- */

b2_reset_stack($dir, $compose);

/* No compose file at all yet: nothing to keep, must still report success. */
$noFileRel = 'zzb2nofile';
$noFileDir = $root.'/'.$noFileRel;
@exec('rm -rf '.escapeshellarg($noFileDir));
mkdir($noFileDir, 0755, true);
$capNote = '';
ok('capture with no compose file present keeps nothing and still succeeds',
   staxx_record_capture($noFileRel, 'compose.yaml', $capNote), $capNote);
ok('...and really kept nothing', staxx_record_list($noFileRel) === []);
@exec('rm -rf '.escapeshellarg($noFileDir));

/* Capturing an existing file keeps it. */
$capNote = '';
ok('capturing an existing compose file succeeds',
   staxx_record_capture($rel, 'compose.yaml', $capNote), $capNote);
$afterFirst = staxx_record_list($rel);
ok('exactly one version exists after the first real capture', count($afterFirst) === 1);

/* Identical content captured twice must not duplicate. */
$capNote = '';
ok('capturing byte-identical content again reports success',
   staxx_record_capture($rel, 'compose.yaml', $capNote), $capNote);
ok('...but produced no second version', count(staxx_record_list($rel)) === 1);

/* A byte-for-byte round trip, including comments, blank lines and trailing
 * whitespace — the exact shape a hand-edited file takes. */
$tricky = "# a comment\nservices:\n  a:\n    image: alpine:3.20   \n\n  # trailing\nvolumes: {}\n";
file_put_contents($dir.'/compose.yaml', $tricky);
$capNote = '';
staxx_record_capture($rel, 'compose.yaml', $capNote);
$list = staxx_record_list($rel);
$newestN = $list[0]['n'];
ok('the byte-for-byte round trip matches exactly',
   staxx_record_get($rel, $newestN) === $tricky);

/* ------------------------------------------------------------ pruning -- */

@exec('rm -rf '.escapeshellarg($recDir));
$pruneRel = 'zzb2prune';
$pruneDir = $root.'/'.$pruneRel;
b2_reset_stack($pruneDir, "services:\n  a:\n    image: alpine:3.0\n");

// Twenty-one distinct saves, each captured before the next value lands on
// disk, exactly mirroring how a real save calls capture() first.
for ($i = 1; $i <= 21; $i++) {
  $body = "services:\n  a:\n    image: alpine:3.$i\n";
  $capNote = '';
  staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
  file_put_contents($pruneDir.'/compose.yaml', $body);
}

$list = staxx_record_list($pruneRel);
$ns = array_column($list, 'n');
ok('exactly 20 versions survive a 21st capture', count($list) === 20);
ok('the oldest (version 1) was the one dropped', !in_array(1, $ns, true));
ok('version 2 through 21 all survive', $ns === range(21, 2));

/* Naming a version keeps it out of the prune even past the boundary. */
@exec('rm -rf '.escapeshellarg($pruneDir.'/.staxx'));
b2_reset_stack($pruneDir, "services:\n  a:\n    image: alpine:3.0\n");
$namedN = null;
for ($i = 1; $i <= 20; $i++) {
  $body = "services:\n  a:\n    image: alpine:3.$i\n";
  $capNote = '';
  staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
  if ($i === 5) {
    $justCaptured = staxx_record_list($pruneRel);
    $namedN = $justCaptured[0]['n'];
  }
  file_put_contents($pruneDir.'/compose.yaml', $body);
}
$nameErr = '';
ok('naming an existing version succeeds',
   staxx_record_name($pruneRel, $namedN, 'kept on purpose', $nameErr), $nameErr);

// Two more saves push the boundary past the named one; it must survive.
for ($i = 21; $i <= 22; $i++) {
  $capNote = '';
  staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
  file_put_contents($pruneDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.$i\n");
}
$list = staxx_record_list($pruneRel);
$ns = array_column($list, 'n');
ok('a named version survives a prune that would otherwise have dropped it',
   in_array($namedN, $ns, true));
ok('20 unnamed plus 1 named leaves 21 total', count($list) === 21);

// One more named version, for the "20 unnamed plus 2 named leaves 22" case.
$justCaptured = staxx_record_list($pruneRel);
$secondCandidate = null;
foreach ($justCaptured as $v) {
  if ($v['n'] !== $namedN && ($v['name'] ?? '') === '') { $secondCandidate = $v['n']; break; }
}
$nameErr = '';
ok('naming a second version succeeds',
   staxx_record_name($pruneRel, $secondCandidate, 'also kept', $nameErr), $nameErr);
// Naming CONVERTS a version rather than adding one, so the total does not
// move — 21 becomes 19 unnamed plus 2 named. One more capture is what takes
// the unnamed count back to its 20, and the total to 22.
$list = staxx_record_list($pruneRel);
$namedCount = count(array_filter($list, fn($v) => ($v['name'] ?? '') !== ''));
ok('naming converts rather than adds — still 21, now 2 named and 19 unnamed',
   count($list) === 21 && $namedCount === 2,
   'total='.count($list).' named='.$namedCount);

file_put_contents($pruneDir.'/compose.yaml', "services:
  a:
    image: alpine:3.98
");
$capNote = '';
staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
$list = staxx_record_list($pruneRel);
$namedCount = count(array_filter($list, fn($v) => ($v['name'] ?? '') !== ''));
$unnamedCount = count($list) - $namedCount;
ok('20 unnamed plus 2 named leaves 22 total', count($list) === 22
   && $namedCount === 2 && $unnamedCount === 20,
   'total='.count($list).' named='.$namedCount.' unnamed='.$unnamedCount);

/* Unnaming puts a version back in the ordinary queue, where being the oldest
 * means it goes at once. Tested on the OLDEST version deliberately: naming the
 * newest and unnaming it proves nothing, because the newest is nowhere near
 * the boundary and would survive either way. */
$list    = staxx_record_list($pruneRel);
$oldestN = $list[count($list) - 1]['n'];

$nameErr = '';
ok('naming the oldest version succeeds',
   staxx_record_name($pruneRel, $oldestN, 'the old one', $nameErr), $nameErr);

for ($i = 0; $i < 5; $i++) {
  file_put_contents($pruneDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.5".$i."\n");
  $capNote = '';
  staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
}
$ns = array_column(staxx_record_list($pruneRel), 'n');
ok('the named oldest version survives five captures that pruned others',
   in_array($oldestN, $ns, true));

$nameErr = '';
ok('unnaming (empty label) succeeds',
   staxx_record_name($pruneRel, $oldestN, '', $nameErr), $nameErr);
$ns = array_column(staxx_record_list($pruneRel), 'n');
ok('unnaming a past-boundary version prunes it immediately',
   !in_array($oldestN, $ns, true));

/* Version numbers are never reused after a prune. Measured rather than
 * hardcoded — a count written in by hand goes stale the moment a case is
 * added above it, and then passes for the wrong reason. */
$maxSeenBefore = max(array_column(staxx_record_list($pruneRel), 'n'));
file_put_contents($pruneDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.99\n");
$capNote = '';
staxx_record_capture($pruneRel, 'compose.yaml', $capNote);
$list = staxx_record_list($pruneRel);
ok('a fresh capture after pruning gets a number higher than any ever issued',
   $list[0]['n'] > $maxSeenBefore,
   'new='.$list[0]['n'].' highest before='.$maxSeenBefore);

@exec('rm -rf '.escapeshellarg($pruneDir));

/* -------------------------------------------------------- both doors -- */

$doorRel = 'zzb2doors';
$doorDir = $root.'/'.$doorRel;
b2_reset_stack($doorDir, "services:\n  a:\n    image: alpine:3.20\n");

$doorErr = '';
ok('staxx_save_stack() on an existing stack succeeds',
   staxx_save_stack($doorRel, "services:\n  a:\n    image: alpine:3.21\n", $doorErr), $doorErr);
ok('...and produces exactly one version', count(staxx_record_list($doorRel)) === 1);

// The compose file itself cannot be reached through the companion-file
// editor at all — staxx_valid_filename() refuses all four of its names, so
// there is no second door to the main file and this must be a refusal.
$doorErr = '';
ok('the file editor cannot write the compose file at all',
   staxx_write_file($doorRel, 'compose.yaml', "services:\n  a:\n    image: alpine:3.22\n", true, $doorErr) === false,
   $doorErr);
ok('...and that refusal left the history alone',
   count(staxx_record_list($doorRel)) === 1);

// The override IS reachable that way, and is compose configuration just as
// much as the main file — so it is the real second door.
$doorErr = '';
ok('the file editor can write the override file',
   staxx_write_file($doorRel, 'compose.override.yaml', "services:\n  a:\n    cpus: 1\n", true, $doorErr),
   $doorErr);
ok('...the first override save keeps nothing, there being no previous version',
   count(staxx_record_list($doorRel)) === 1);

$doorErr = '';
ok('the file editor can write the override file a second time',
   staxx_write_file($doorRel, 'compose.override.yaml', "services:\n  a:\n    cpus: 2\n", true, $doorErr),
   $doorErr);
$doorVersions = staxx_record_list($doorRel);
ok('...and that overwrite IS kept, giving two versions',
   count($doorVersions) === 2, 'count='.count($doorVersions));
ok('...recorded against the override, not the main file',
   ($doorVersions[0]['file'] ?? '') === 'compose.override.yaml',
   'file='.($doorVersions[0]['file'] ?? '?'));
ok('...holding what the override said before that save',
   staxx_record_get($doorRel, $doorVersions[0]['n']) === "services:\n  a:\n    cpus: 1\n");

$doorErr = '';
ok('staxx_write_file() writing a non-compose companion file succeeds',
   staxx_write_file($doorRel, '.env', "A=1\n", true, $doorErr), $doorErr);
ok('...and produces no additional version', count(staxx_record_list($doorRel)) === 2);

// A path where a filename belongs used to keep nothing and report success —
// the exact way the first wiring of door one shipped broken and silent.
$pathNote = '';
ok('capture refuses a full path instead of silently keeping nothing',
   staxx_record_capture($doorRel, $doorDir.'/compose.yaml', $pathNote) === false && $pathNote !== '',
   $pathNote);

@exec('rm -rf '.escapeshellarg($doorDir));

/* -------------------------------------------------------- kept out of the way -- */

$hideRel = 'zzb2hide';
$hideDir = $root.'/'.$hideRel;
b2_reset_stack($hideDir, "services:\n  a:\n    image: alpine:3.20\n");
$capNote = '';
staxx_record_capture($hideRel, 'compose.yaml', $capNote);
file_put_contents($hideDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.21\n");

$listing = staxx_list_files($hideRel, $note);
$names = array_column((array)$listing, 'name');
ok('staxx_list_files() never lists the record directory', !in_array('.staxx', $names, true));

$rwErr = '';
ok('reading a file inside the record directory through the companion tools is refused',
   staxx_read_file($hideRel, '.staxx/record.json', $rwErr) === null);
ok('...with a full-sentence refusal', str_word_count($rwErr) >= 3, $rwErr);

$rwErr = '';
ok('writing into the record directory through the companion tools is refused',
   !staxx_write_file($hideRel, '.staxx/smuggled.txt', 'x', true, $rwErr));
ok('...with a full-sentence refusal', str_word_count($rwErr) >= 3, $rwErr);
ok('nothing was actually written', !file_exists($hideDir.'/.staxx/smuggled.txt'));

$rwErr = '';
ok('renaming something onto/out of the record directory is refused',
   !staxx_rename_file($hideRel, '.staxx', 'notrecord', $rwErr));
ok('...with a full-sentence refusal', str_word_count($rwErr) >= 3, $rwErr);

$rwErr = '';
ok('deleting the record directory through the companion tools is refused',
   !staxx_delete_file($hideRel, '.staxx', $rwErr));
ok('...with a full-sentence refusal', str_word_count($rwErr) >= 3, $rwErr);
ok('the record directory is still there', is_dir($hideDir.'/.staxx'));

@exec('rm -rf '.escapeshellarg($hideDir));

/* ------------------------------------------------------------ travelling -- */

$travelRel = 'zzb2travel';
$travelDir = $root.'/'.$travelRel;
b2_reset_stack($travelDir, "services:\n  a:\n    image: alpine:3.20\n");
$capNote = '';
staxx_record_capture($travelRel, 'compose.yaml', $capNote);
file_put_contents($travelDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.21\n");

$archiveErr = '';
$archive = null;
ok('archiving a stack with a record succeeds',
   staxx_archive_stack($travelRel, $archiveErr, true, $archive), $archiveErr);
ok('the archive file exists', is_string($archive) && is_file($archive));

$zipList = staxx_sh('unzip -l '.escapeshellarg($archive));
foreach (["$travelRel/.staxx/record.json", "$travelRel/.staxx/history/"] as $want) {
  ok('the archive contains '.$want, strpos($zipList, $want) !== false);
}

/* ---------------------------------------------------------------------- */

b2_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
