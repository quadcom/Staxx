<?php
/* PLAN_101/PLAN_101a — the bundle importer's refusals, since they are the point (see PLAN_101.md's
 * own Checks section and PLAN_101a.md's, which name this suite explicitly). Every numbered case
 * below must refuse and leave the disk unchanged; two "accept" cases and two write cases at the
 * end prove the suite is not only proving failure.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine.
 *
 * staxx_bundle_read() never touches STORE_ROOT — it works entirely under STAXX_CFILE_TMP
 * (/tmp/staxx/cfile), so all eighteen refusal cases and the two read-success cases (19, 20) run
 * against whatever config is already on disk, untouched. Only staxx_bundle_write() (cases 21, 22)
 * creates a stack, so only those two need a scratch STORE_ROOT. staxx_cfg() memoises on first
 * read, so a key has to be seeded into the config file BEFORE php starts — it cannot be changed
 * part-way through this script — which is why the whole run below is wrapped in a redirected
 * config even though only the last two cases actually touch the store:
 *
 *     pscp tests/server/bundle.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzb-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzb-store\"" >> $CFG
 *       php /tmp/bundle.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * The restore happens in the shell wrapper on every exit path, php dying outright included — never
 * from inside this script, the same pattern tests/server/export.php and record.php use. NEVER point
 * this at the real store: this is Adrian's production server, and moving it makes every real stack
 * vanish from the webGUI for as long as it is moved.
 *
 * CRAFTED NAMES. PLAN_101 names four entry names a zip cannot hold by zipping a real file:
 * "../escape.yaml", "/abs.yaml", "..\win.yaml" and "C:drive.yaml". Working through them: only "/"
 * and the NUL byte are actually forbidden in a single Linux filename component — a backslash and a
 * colon are perfectly ordinary bytes to ext4 — so the backslash name and the drive-letter name are
 * built as REAL files (case 3, case 4) and zipped normally. The other two genuinely cannot exist as
 * a real path: ".." only ever names the parent directory, so a directory component literally called
 * ".." can never be created, and a leading "/" makes a name absolute rather than being part of one.
 * Those two (case 1, case 2) are built by hand with zzb_raw_zip(), below, which writes a minimal
 * stored-method zip byte for byte from the documented format — one local file header, one central
 * directory record, one end-of-central-directory record, no library involved. All eighteen refusals
 * are exercised; none had to be marked skipped.
 *
 * Prints one line per case and exits non-zero on any failure. Safe to run twice: every fixture
 * lives under /tmp and is removed on the way out, win or lose.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$WORK = '/tmp/zzb-work';
@exec('rm -rf '.escapeshellarg($WORK));
mkdir($WORK, 0755, true);

/* ============================================================
 * Fixture helpers
 * ============================================================ */

/**
 * Build a minimal stored-method (uncompressed) zip by hand, one entry per
 * array item, straight from the documented ZIP format: a local file header
 * per entry, a central directory record per entry, one end-of-central-
 * directory record. This is the only way to put a name like "../escape.yaml"
 * or "/abs.yaml" into a zip's own listing — neither can exist as a real path
 * on this filesystem, so there is no file to hand to the real `zip` binary.
 */
function zzb_raw_zip(array $entries): string {
  $body = ''; $central = ''; $offset = 0; $count = 0;
  foreach ($entries as $name => $content) {
    $crc      = crc32($content);
    $len      = strlen($content);
    $nameLen  = strlen($name);
    $local = pack('VvvvvvVVVvv',
      0x04034b50, 20, 0, 0, 0, 0x21, $crc, $len, $len, $nameLen, 0
    ).$name.$content;
    $central .= pack('VvvvvvvVVVvvvvvVV',
      0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $len, $len, $nameLen, 0, 0, 0, 0, 0, $offset
    ).$name;
    $offset += strlen($local);
    $body   .= $local;
    $count++;
  }
  $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
  return $body.$central.$eocd;
}

/**
 * Build a real zip from a staging folder $populate fills in, using the real
 * `zip` binary — this is the ordinary path every genuine bundle takes.
 * $withSymlinks passes `-y` so a symlink is stored as a link rather than
 * followed, which is exactly how case 18's fixture has to be built.
 */
function zzb_build_zip(string $work, string $case, callable $populate, bool $withSymlinks = false): string {
  $src = $work.'/'.$case.'-src';
  @exec('rm -rf '.escapeshellarg($src));
  mkdir($src, 0755, true);
  $populate($src);
  $zipFile = $work.'/'.$case.'.zip';
  @unlink($zipFile);
  $flag = $withSymlinks ? '-y' : '';
  exec('cd '.escapeshellarg($src).' && zip -q -X '.$flag.' -r '.escapeshellarg($zipFile).' . 2>&1', $out, $code);
  $bytes = ($code === 0 && is_file($zipFile)) ? (string)file_get_contents($zipFile) : '';
  @unlink($zipFile);
  @exec('rm -rf '.escapeshellarg($src));
  return $bytes;
}

/** Anything staxx_bundle_read() left behind under its own scratch folder. */
function zzb_cfile_leftovers(): array {
  return glob(STAXX_CFILE_TMP.'/bundle-*') ?: [];
}

$composeOk = "services:\n  a:\n    image: alpine:3.20\n";
$pngBytes  = "\x89PNG\r\n\x1a\n".str_repeat('x', 32); // real PNG magic, fake body — good enough

/* ============================================================
 * The eighteen refusals — every one must refuse, and leave nothing behind
 * in STAXX_CFILE_TMP once it has (staxx_bundle_read()'s own "finally").
 * ============================================================ */

// 1. a name containing ".." — hand-built, see the header note.
$bytes = zzb_raw_zip(['../escape.yaml' => 'x']);
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('1. a ".." entry is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('   ...for stepping outside the bundle', stripos($error, 'step outside') !== false, $error);

// 2. an absolute name (leading "/") — hand-built, see the header note.
$bytes = zzb_raw_zip(['/abs.yaml' => 'x']);
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('2. a leading "/" entry is refused', $r === null && $error !== '' && $error !== 'unset', $error);

// 3. a name with a backslash — a real file. Only "/" and NUL are forbidden
// in one Linux filename component, so "..\win.yaml" is a perfectly ordinary
// (if odd-looking) filename to create directly.
$bytes = zzb_build_zip($WORK, 'case3', function (string $src) {
  file_put_contents($src.'/..\\win.yaml', 'x');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('3. a backslash entry is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('   ...for holding a backslash', stripos($error, 'backslash') !== false, $error);

// 4. a drive-letter name — also a real, legal filename on this filesystem.
$bytes = zzb_build_zip($WORK, 'case4', function (string $src) {
  file_put_contents($src.'/C:drive.yaml', 'x');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('4. a drive-letter entry is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('   ...for naming a drive', stripos($error, 'drive') !== false, $error);

// 5. nesting deeper than the one record folder. zip -r adds a directory
// entry for "sub/" as it recurses, and no folder but ".staxx/" is ever
// permitted — so this is caught by the bare-folder check, which is exactly
// the same "no nesting beyond the record folder" rule in spirit.
$bytes = zzb_build_zip($WORK, 'case5', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/sub/dir', 0755, true);
  file_put_contents($src.'/sub/dir/file.txt', 'x');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('5. nesting outside the record folder is refused', $r === null && $error !== '' && $error !== 'unset', $error);

// 6. an entry name that is none of the four allowed shapes — a space is
// outside staxx_valid_filename()'s character set, so this falls through
// every recognised shape to the catch-all refusal.
$bytes = zzb_build_zip($WORK, 'case6', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/bad name.txt', 'x');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('6. an unrecognised entry shape is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('   ...naming it as unimportable', stripos($error, 'not a name this can import') !== false, $error);

// 7. a planted history entry inside the record folder. The real per-stack
// history lives at ".staxx/history/0001.yaml" (see Record.php) — that shape
// nests two levels under the record folder, so it is caught the same way
// case 5 is: the bare "history/" directory entry is not the record folder
// itself, and is refused before the planted file inside it is even reached.
$bytes = zzb_build_zip($WORK, 'case7', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx/history', 0755, true);
  file_put_contents($src.'/.staxx/history/0001.yaml', "fake: history\n");
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('7. a planted history entry is refused', $r === null && $error !== '' && $error !== 'unset', $error);

// 8. a planted record.json inside the record folder. Unlike case 7 this is a
// FLAT name directly under ".staxx/", so it passes the name-shape check as a
// candidate icon (the only flat shape the record folder is allowed to hold)
// and is only stopped once its bytes are checked and found not to be any
// picture format at all — a real refusal, reached one step later than the
// name check, and worth documenting so nobody "fixes" this into failing.
$bytes = zzb_build_zip($WORK, 'case8', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/record.json', '{"fake":"record"}');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('8. a planted record.json is refused', $r === null && $error !== '' && $error !== 'unset', $error);
// Refused by its NAME, not merely by its bytes: the record folder's only
// permitted entry beyond the marker is a picture, recognised by its
// extension. A planted record file must never get as far as having its
// contents read at all.
ok('   ...for not being a name this can import', stripos($error, 'not a name this can import') !== false, $error);

// 9. a bundle marker naming a version above 1.
$bytes = zzb_build_zip($WORK, 'case9', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/bundle.json', '{"format":"staxx-bundle","version":2}');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('9. a newer bundle marker version is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('   ...for being from a newer StaXX', stripos($error, 'newer version') !== false, $error);

// 10a. a bundle marker that is not valid JSON.
$bytes = zzb_build_zip($WORK, 'case10a', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/bundle.json', 'not json at all {{{');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('10a. an unparsable bundle marker is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for not being one StaXX wrote', stripos($error, 'not one StaXX wrote') !== false, $error);

// 10b. a bundle marker that does not name "staxx-bundle".
$bytes = zzb_build_zip($WORK, 'case10b', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/bundle.json', '{"format":"something-else","version":1}');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('10b. a marker naming the wrong format is refused', $r === null && $error !== '' && $error !== 'unset', $error);

// 11. no compose file at all.
$bytes = zzb_build_zip($WORK, 'case11', function (string $src) {
  file_put_contents($src.'/notes.txt', 'just some notes');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('11. a bundle with no compose file is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for having nothing to import', stripos($error, 'nothing to import') !== false, $error);

// 12. two compose files.
$bytes = zzb_build_zip($WORK, 'case12', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/compose.yml', $composeOk);
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('12. two compose files are refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for being unclear which to import', stripos($error, 'more than one compose file') !== false, $error);

// 13. a companion file that is binary.
$bytes = zzb_build_zip($WORK, 'case13', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/binary.dat', "\x00\x01\x02binary-looking bytes");
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('13. a binary companion file is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for looking like a binary file', stripos($error, 'binary') !== false, $error);

// 14. a picture that is not actually a picture (correct extension, wrong bytes).
$bytes = zzb_build_zip($WORK, 'case14', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/icon.png', 'not really a png, just named like one');
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('14. a fake picture is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for not actually being a picture', stripos($error, 'not actually a picture') !== false, $error);

// 15. a bundle over STAXX_BUNDLE_MAX. Size is checked before anything is
// parsed as a zip, so the content itself does not have to be a real bundle.
$bytes = str_repeat('x', STAXX_BUNDLE_MAX + 1024);
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('15. an over-cap bundle is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for being over the KiB limit', stripos($error, 'KiB limit for one bundle') !== false, $error);

// 16. a bundle that is not a zip at all.
$bytes = "this is plain text, not a zip file\n";
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('16. a non-zip file is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for not being a bundle StaXX can read', stripos($error, 'not a bundle StaXX can read') !== false, $error);

// 17. an entry count over STAXX_EXPORT_FILES_MAX.
$bytes = zzb_build_zip($WORK, 'case17', function (string $src) {
  for ($i = 0; $i <= STAXX_EXPORT_FILES_MAX; $i++) {
    file_put_contents($src.'/f'.$i.'.txt', 'x');
  }
});
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('17. too many entries is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for being over the entry limit', stripos($error, 'entry limit') !== false, $error);

// 18. a symlink inside the bundle — `zip -y` stores it as a link rather than
// following it, which is exactly how staxx_bundle_walk() catches it: it is
// refused after extraction, once is_link() can actually see it.
$bytes = zzb_build_zip($WORK, 'case18', function (string $src) use ($composeOk) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/target.txt', 'the real file');
  symlink($src.'/target.txt', $src.'/link.txt');
}, true);
$error = 'unset';
$r = staxx_bundle_read($bytes, $error);
ok('18. a symlink inside the bundle is refused', $r === null && $error !== '' && $error !== 'unset', $error);
ok('    ...for holding a link', stripos($error, 'link') !== false, $error);

ok('nothing was left behind under STAXX_CFILE_TMP after the 18 refusals', zzb_cfile_leftovers() === [],
   implode(', ', zzb_cfile_leftovers()));

/* ============================================================
 * 19–20 — reading a bundle that should succeed
 * ============================================================ */

// 19. a minimal valid bundle with a marker.
$bytes = zzb_build_zip($WORK, 'case19', function (string $src) use ($composeOk, $pngBytes) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/notes.txt', 'a companion file');
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/bundle.json', '{"format":"staxx-bundle","version":1}');
  file_put_contents($src.'/.staxx/icon.png', $pngBytes);
});
$error = 'unset';
$r19 = staxx_bundle_read($bytes, $error);
ok('19. a minimal marked bundle is accepted', $r19 !== null, $error);
ok('    ...with the right compose name', ($r19['compose']['name'] ?? '') === 'compose.yaml');
ok('    ...with the companion file', count($r19['files'] ?? []) === 1 && ($r19['files'][0]['name'] ?? '') === 'notes.txt');
ok('    ...with the icon', ($r19['icon']['name'] ?? '') === 'icon.png' && ($r19['icon']['bytes'] ?? '') === $pngBytes);
ok('    ...marked true, version 1', ($r19['marked'] ?? null) === true && ($r19['version'] ?? null) === 1);

// 20. the same bundle with no marker — accepted as version 1, unmarked
// (PLAN_101a decision 1).
$bytes = zzb_build_zip($WORK, 'case20', function (string $src) use ($composeOk, $pngBytes) {
  file_put_contents($src.'/compose.yaml', $composeOk);
  file_put_contents($src.'/notes.txt', 'a companion file');
  mkdir($src.'/.staxx', 0755, true);
  file_put_contents($src.'/.staxx/icon.png', $pngBytes);
});
$error = 'unset';
$r20 = staxx_bundle_read($bytes, $error);
ok('20. an unmarked bundle is accepted', $r20 !== null, $error);
ok('    ...marked false, version 1', ($r20['marked'] ?? null) === false && ($r20['version'] ?? null) === 1);

ok('nothing was left behind under STAXX_CFILE_TMP after the two reads', zzb_cfile_leftovers() === [],
   implode(', ', zzb_cfile_leftovers()));

/* ============================================================
 * 21–22 — staxx_bundle_write(), which DOES create a stack. This is the
 * only reason STORE_ROOT is redirected for this whole run — see the header.
 * ============================================================ */

if (staxx_stack_root() !== '/tmp/zzb-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(max($fails, 1));
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg(dirname($root)));
mkdir($root, 0755, true);

// 21. a fresh scratch store — everything staxx_bundle_read() (case 19)
// reported should land, apart from the marker, and nothing else should ever
// reach the record folder.
$error = 'unset';
$wrote = staxx_bundle_write($r19, 'zzbnew', $error);
ok('21. writing a fresh bundle succeeds', $wrote === true, $error);

$newDir = $root.'/zzbnew';
ok('    ...the compose file lands', file_get_contents($newDir.'/compose.yaml') === $composeOk);
ok('    ...the companion file lands', file_get_contents($newDir.'/notes.txt') === 'a companion file');
ok('    ...the picture lands in the stack\'s own record folder',
   file_get_contents($newDir.'/'.STAXX_RECORD_DIR.'/icon.png') === $pngBytes);
ok('    ...the bundle marker does NOT land', !file_exists($newDir.'/'.STAXX_RECORD_DIR.'/bundle.json'));
// The record folder also gains this stack's OWN history — a version 1 and
// its record file — because the compose file was written through the
// ordinary save, which captures one. That is StaXX's own machinery and is
// wanted: it is what makes the import undoable. What must never appear is
// anything the BUNDLE put there, so the check is that every entry is one
// this side wrote, and the marker is not among them.
$recordEntries = array_values(array_diff(scandir($newDir.'/'.STAXX_RECORD_DIR) ?: [], ['.', '..']));
sort($recordEntries);
$allowed = ['history', 'icon.png', 'record.json'];
ok('    ...nothing the bundle carried appears in the record folder',
   array_values(array_diff($recordEntries, $allowed)) === [], implode(', ', $recordEntries));
ok('    ...the history it gained is one version deep, written by this side',
   !is_dir($newDir.'/'.STAXX_RECORD_DIR.'/history')
     || count(array_diff(scandir($newDir.'/'.STAXX_RECORD_DIR.'/history') ?: [], ['.', '..'])) <= 1);

// 22. writing onto a name already in use — refused, and the existing stack
// is left byte for byte unchanged.
$existingRel = 'zzbexisting';
$existingDir = $root.'/'.$existingRel;
mkdir($existingDir, 0755, true);
$existingContent = "services:\n  a:\n    image: THE-EXISTING-STACK-MUST-NOT-CHANGE\n";
file_put_contents($existingDir.'/compose.yaml', $existingContent);
$before = file_get_contents($existingDir.'/compose.yaml');

$error = 'unset';
$wrote = staxx_bundle_write($r19, $existingRel, $error);
ok('22. writing onto a name already in use is refused', $wrote === false && $error !== '' && $error !== 'unset', $error);
$after = file_get_contents($existingDir.'/compose.yaml');
ok('    ...and the existing stack is byte for byte unchanged', $after === $before && $after === $existingContent);

@exec('rm -rf '.escapeshellarg(dirname($root)));
@exec('rm -rf '.escapeshellarg($WORK));

echo $fails === 0 ? "\nAll bundle.php cases passed.\n" : "\n$fails case(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
