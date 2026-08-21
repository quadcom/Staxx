<?php
/* The companion-file helpers, checked against the real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STACK_ROOT
 * pointed at /tmp/b1-root and ARCHIVE_ROOT at /tmp/b1-archives, the same way
 * tests/server/links.php does — STACK_ROOT because the permission case near
 * the end has to land on a real filesystem, not /boot's vfat, which takes its
 * mode from the mount and would pass that case for the wrong reason; the
 * caller sets both and puts the config back:
 *
 *     pscp tests/server/files.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/b1-root\"#" $CFG
 *       grep -q "^ARCHIVE_ROOT=" $CFG \
 *         && sed -i "s#^ARCHIVE_ROOT=.*#ARCHIVE_ROOT=\"/tmp/b1-archives\"#" $CFG \
 *         || echo "ARCHIVE_ROOT=\"/tmp/b1-archives\"" >> $CFG
 *       php /tmp/files.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzb1test" and a handful of "zz…" siblings, under
 * the temporary stack root. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/b1-root') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

/* ---------------------------------------------------- valid_filename ---- */

$good = ['.env', '.env.local', 'app.env', 'a', 'my-file_1.conf', 'cert.pem', str_repeat('a', 63)];
$bad  = ['', '..', '.', '../x', 'a/b', '.env/../x', '-lead', '_lead', '.', 'compose.yaml',
         'Compose.YAML', 'docker-compose.yml', str_repeat('a', 64), 'sp ace', "nul\0byte",
         '..env', '.'];

foreach ($good as $g) ok('accepts '.var_export($g, true), staxx_valid_filename($g));
foreach ($bad  as $b) ok('rejects '.var_export($b, true), !staxx_valid_filename($b));

/* ------------------------------------------------------------ fixture ---- */

$rel  = 'zzb1test';
$root = staxx_stack_root();
$dir  = $root.'/'.$rel;

@exec('rm -rf '.escapeshellarg($dir));
mkdir($dir, 0755, true);
file_put_contents($dir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';

/* --------------------------------------------------------- save-stack ---- */
// staxx_save_stack() writes the compose file itself, on its own atomic-write
// and permission path (PLAN_60 3.2/3.3) — never staxx_write_file()'s, which
// is checked separately below for the companion files it actually handles.

$saveRel = 'zzb1save';
$saveDir = $root.'/'.$saveRel;
@exec('rm -rf '.escapeshellarg($saveDir));

$saveYaml = "services:\n  a:\n    image: alpine:3.20\n";
ok('saves a new stack', staxx_save_stack($saveRel, $saveYaml, $err), $err);
$saveFile = $saveDir.'/compose.yaml';
ok('the file exists', file_exists($saveFile));
ok('the content landed byte-for-byte', file_get_contents($saveFile) === $saveYaml);
// The stack root is pinned at /tmp/b1-root for this run (a real filesystem,
// not /boot's vfat, which ignores chmod entirely) — precisely so this case
// means something rather than passing for the wrong reason.
ok('it is not world-readable', (fileperms($saveFile) & 0777) === 0600,
   sprintf('got %o', fileperms($saveFile) & 0777));
ok('no temp file left behind', count(glob($saveDir.'/compose.yaml.*.tmp')) === 0);

@exec('rm -rf '.escapeshellarg($saveDir));

/* ------------------------------------------------------------ fingerprint -- */
// staxx_stack_fingerprint() is what the save endpoint compares against the
// browser's own copy to refuse a write that would clobber a change made
// elsewhere since — see action.php's 'save' case (PLAN_60 3.1). Same content
// must hash the same and changed content must hash differently, or that
// refusal could never fire, or would fire on every save.

$fp1 = staxx_stack_fingerprint($rel);
ok('fingerprint is non-empty for an existing file', $fp1 !== '');
ok('fingerprint is stable for unchanged content', staxx_stack_fingerprint($rel) === $fp1);

file_put_contents($dir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.21\n");
$fp2 = staxx_stack_fingerprint($rel);
ok('fingerprint changes when the file changes', $fp2 !== '' && $fp2 !== $fp1);

ok('fingerprint is empty for a stack with no compose file',
   staxx_stack_fingerprint('zznocomposefile') === '');

/* ------------------------------------------------------------- writing -- */

ok('writes a text file', staxx_write_file($rel, '.env', "A=1\r\nB=2\r\n", true, $err), $err);
ok('keeps CRLF',         file_get_contents($dir.'/.env') === "A=1\r\nB=2\r\n");
ok('leaves no temp file', count(glob($dir.'/.env.*.staxx-tmp')) === 0);

ok('writes an LF file', staxx_write_file($rel, 'lf.env', "C=3\nD=4\n", true, $err), $err);
ok('keeps LF, invents no CR', file_get_contents($dir.'/lf.env') === "C=3\nD=4\n");

$bin = "\x00\x01\x02binary\xff";
ok('writes a binary file', staxx_write_file($rel, 'cert.der', $bin, false, $err), $err);
ok('binary is byte-exact', file_get_contents($dir.'/cert.der') === $bin);

ok('refuses a compose filename',
   !staxx_write_file($rel, 'compose.yml', 'x', true, $err), $err);
ok('refuses a traversing name',
   !staxx_write_file($rel, '../escaped', 'x', true, $err), $err);
ok('nothing escaped', !file_exists($root.'/escaped'));

ok('refuses over the cap',
   !staxx_write_file($rel, 'big.bin', str_repeat('x', STAXX_FILE_MAX + 1), false, $err), $err);
ok('accepts exactly the cap',
   staxx_write_file($rel, 'big.bin', str_repeat('x', STAXX_FILE_MAX), false, $err), $err);
ok('leaves no temp file after a refusal', count(glob($dir.'/big.bin.*.staxx-tmp')) === 0);

/* ------------------------------------------------------------- reading -- */

$read = staxx_read_file($rel, '.env', $err);
ok('reads text', is_array($read) && ($read['binary'] ?? true) === false
                 && ($read['text'] ?? '') === "A=1\r\nB=2\r\n", $err);

$read = staxx_read_file($rel, 'cert.der', $err);
ok('reads binary as base64', is_array($read) && ($read['binary'] ?? false) === true
                 && base64_decode($read['b64'] ?? '') === $bin, $err);

// Put it there behind the helper's back — the writer refuses to make one this
// big, and a file that grew outside the plugin is exactly the case to cover.
file_put_contents($dir.'/huge.bin', str_repeat('x', STAXX_FILE_MAX + 1));
ok('refuses to read over the cap', staxx_read_file($rel, 'huge.bin', $err) === null, $err);
ok('and says how big it is', strpos($err, 'KiB') !== false, $err);
ok('refuses a missing file', staxx_read_file($rel, 'nothere.txt', $err) === null, $err);

/* ------------------------------------------------------------ listing --- */

$list = staxx_list_files($rel, $err);
ok('lists the folder', is_array($list), $err);
ok('compose file is first', ($list[0]['name'] ?? '') === 'compose.yaml' && $list[0]['compose']);
$byName = [];
foreach ((array)$list as $e) $byName[$e['name']] = $e;
ok('.env is listed as text', ($byName['.env']['text'] ?? false) === true);
ok('binary is not listed as text', ($byName['cert.der']['text'] ?? true) === false);
ok('sizes are real', ($byName['.env']['size'] ?? 0) === 10);
ok('mtimes are set', ($byName['.env']['mtime'] ?? 0) > 0);

/* ------------------------------------------------------------ renaming -- */

ok('renames', staxx_rename_file($rel, 'cert.der', 'cert2.der', $err), $err);
ok('the old name is gone', !file_exists($dir.'/cert.der'));
ok('the new name is there', file_exists($dir.'/cert2.der'));
ok('refuses to overwrite', !staxx_rename_file($rel, 'cert2.der', '.env', $err), $err);
ok('refuses to rename onto a compose file',
   !staxx_rename_file($rel, 'cert2.der', 'compose.yml', $err), $err);

/* ------------------------------------------------------------ deleting -- */

ok('deletes', staxx_delete_file($rel, 'big.bin', $err), $err);
ok('the file is gone', !file_exists($dir.'/big.bin'));
ok('refuses a missing file', !staxx_delete_file($rel, 'big.bin', $err), $err);

mkdir($dir.'/sub', 0755);
file_put_contents($dir.'/sub/one.txt', 'x');
ok('refuses to delete a folder', !staxx_delete_file($rel, 'sub', $err), $err);

/* Links are covered by links.php beside this file instead. The stack root
 * defaults to /boot, which is vfat and cannot hold a symlink at all, so
 * symlink() here simply fails and every link case would pass for the wrong
 * reason. */

/* -------------------------------------------------------------- extras -- */

$extras = staxx_stack_extras($rel, $err);
ok('lists extras', is_array($extras), $err);
$names = array_column((array)$extras, 'name');
ok('the compose file is not an extra', !in_array('compose.yaml', $names, true));
ok('the .env is an extra', in_array('.env', $names, true));
ok('the folder is an extra', in_array('sub', $names, true));
foreach ((array)$extras as $e) {
  if ($e['name'] === 'sub') ok('the folder counts its contents', $e['dir'] && $e['count'] === 1);
}
ok('extras refuses a missing stack', staxx_stack_extras('nosuchstack', $err) === null, $err);

/* ---------------------------------------------------------- share-perms -- */
// ARCHIVE_ROOT is pinned at /tmp for this whole file, so every archive case
// below exercises staxx_share_perms() as a no-op by construction — a test
// asserting nobody:users ownership here would never be able to fail. What
// IS meaningful without touching /mnt is the guard itself: a path outside
// /mnt/ must come back untouched.
$permFile = $dir.'/permguard.txt';
file_put_contents($permFile, 'x');
chmod($permFile, 0644);
$before = fileperms($permFile) & 0777;
staxx_share_perms($permFile, false);
ok('share-perms leaves a /tmp path untouched',
   (fileperms($permFile) & 0777) === $before);
unlink($permFile);

/* -------------------------------------------------------------- archive -- */
// $rel/$dir here are still "zzb1test" from the top of the file, which by now
// holds extras (.env, lf.env, cert2.der, huge.bin) and the "sub" subfolder
// from the tests above — exactly the "extras plus a subfolder" shape the
// first case needs, so there is no reason to build a second fixture for it.

$archiveRoot = staxx_archive_root();
if ($archiveRoot !== '/tmp/b1-archives') {
  echo "FAIL   the temporary archive root is not in place (got $archiveRoot)\n";
  exit(1);
}
@exec('rm -rf '.escapeshellarg($archiveRoot));
mkdir($archiveRoot, 0755, true);

$archive = null;
ok('unconfirmed archive refuses', !staxx_archive_stack($rel, $err, false, $archive), $err);
ok('and leaves no error text — the endpoint asks the question', $err === '');
ok('nothing was touched', is_dir($dir) && file_exists($dir.'/sub/one.txt'));

// A pending handover is refused before anything is stopped or zipped, even
// once confirmed — stranding that set-aside container would leave nothing
// able to say what it was called or how to put it back.
$hRel = 'zzhandover';
$hDir = $root.'/'.$hRel;
@exec('rm -rf '.escapeshellarg($hDir));
mkdir($hDir, 0755, true);
file_put_contents($hDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($hDir.'/HANDOVER.md', 'waiting for an answer');
$hArchive = null;
ok('a pending handover refuses the archive',
   !staxx_archive_stack($hRel, $err, true, $hArchive), $err);
ok('and says why', strpos($err, 'set aside') !== false, $err);
ok('nothing was stopped or zipped', is_dir($hDir) && file_exists($hDir.'/HANDOVER.md'));
ok('and nothing landed in the archive folder', count(glob($archiveRoot.'/*.zip')) === 0);
@exec('rm -rf '.escapeshellarg($hDir));

// The confirmed archive: extras and the subfolder all end up in the zip,
// under the stack's own folder name — and the folder itself is gone after.
ok('confirmed archive succeeds', staxx_archive_stack($rel, $err, true, $archive), $err);
ok('the stack folder is gone', !is_dir($dir));
ok('$archive names a file that exists', is_string($archive) && is_file($archive));
ok('it landed in the archive folder', dirname($archive) === $archiveRoot);

$listing = staxx_sh('unzip -l '.escapeshellarg($archive));
foreach (['zzb1test/compose.yaml', 'zzb1test/.env', 'zzb1test/lf.env',
          'zzb1test/cert2.der', 'zzb1test/huge.bin', 'zzb1test/sub/one.txt'] as $want) {
  ok('zip lists '.$want, strpos($listing, $want) !== false);
}

/* Two stacks with the same leaf name in different folders must not collide —
 * the "/" in a stack's path becomes "-" in the archive name, so their
 * archives differ even though both stacks are called "zzleaf". */

foreach (['zzarcA', 'zzarcB'] as $folder) {
  $d = $root.'/'.$folder.'/zzleaf';
  @exec('rm -rf '.escapeshellarg($root.'/'.$folder));
  mkdir($d, 0755, true);
  file_put_contents($d.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
}
$archiveA = null; $archiveB = null;
ok('folder A\'s zzleaf archives', staxx_archive_stack('zzarcA/zzleaf', $err, true, $archiveA), $err);
ok('folder B\'s zzleaf archives', staxx_archive_stack('zzarcB/zzleaf', $err, true, $archiveB), $err);
ok('the two archives have different names', $archiveA !== $archiveB);
ok('both files exist', is_file($archiveA) && is_file($archiveB));
ok('both stack folders are gone',
   !is_dir($root.'/zzarcA/zzleaf') && !is_dir($root.'/zzarcB/zzleaf'));
@exec('rm -rf '.escapeshellarg($root.'/zzarcA').' '.escapeshellarg($root.'/zzarcB'));

/* A name collision within the same second bumps to "-2" rather than
 * overwriting — forced by pre-creating the exact name the archive would
 * otherwise get. */

$collRel = 'zzcoll';
$collDir = $root.'/'.$collRel;
@exec('rm -rf '.escapeshellarg($collDir));
mkdir($collDir, 0755, true);
file_put_contents($collDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$claimed = $archiveRoot.'/'.staxx_archive_name($collRel);
file_put_contents($claimed, 'not a real archive — just claiming the name');

$collArchive = null;
ok('archiving onto a claimed name still succeeds',
   staxx_archive_stack($collRel, $err, true, $collArchive), $err);
ok('it did not overwrite the claimed name',
   file_get_contents($claimed) === 'not a real archive — just claiming the name');
// Asserted as "not the claimed name" rather than "-2" outright: if the run
// crosses a second boundary between claiming the name and archiving, the
// timestamp differs and no bump is needed. Either way it must not be the
// file already sitting there.
ok('it did not reuse the claimed name', $collArchive !== $claimed);
if (basename($collArchive) !== basename($claimed)
    && strpos(basename($collArchive), substr(basename($claimed), 0, -4)) === 0) {
  ok('it bumped to -2 instead', substr($collArchive, -6) === '-2.zip');
}
ok('the stack folder is gone', !is_dir($collDir));

/* An archive folder that cannot even be created refuses, leaving the stack
 * exactly as it was. staxx_archive_root() is fixed to /tmp/b1-archives for
 * this whole run (see the header), so the folder itself is swapped out for a
 * plain file to make that path uncreatable, then put back afterwards. */

$unwriteRel = 'zzunwrite';
$unwriteDir = $root.'/'.$unwriteRel;
@exec('rm -rf '.escapeshellarg($unwriteDir));
mkdir($unwriteDir, 0755, true);
file_put_contents($unwriteDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($unwriteDir.'/.env', 'A=1');

// Renamed aside rather than deleted — the earlier archives already written
// this run (archiveA, archiveB, collArchive…) must still be there afterwards
// for the archive-list check below.
rename($archiveRoot, $archiveRoot.'.bak');
file_put_contents($archiveRoot, 'a file sitting where the archive folder should be');

$unwriteArchive = null;
ok('an uncreatable archive folder refuses',
   !staxx_archive_stack($unwriteRel, $err, true, $unwriteArchive), $err);
ok('and says so', $err !== '');
ok('the stack folder is untouched',
   is_dir($unwriteDir) && file_exists($unwriteDir.'/.env') && file_exists($unwriteDir.'/compose.yaml'));

@unlink($archiveRoot);
rename($archiveRoot.'.bak', $archiveRoot);
@exec('rm -rf '.escapeshellarg($unwriteDir));

/* -------------------------------------------------------------- archive-list -- */

$list = staxx_archive_list();
ok('archive-list returns an array', is_array($list));
$names = array_column($list, 'name');
ok('it only lists zips', count(array_filter($names, fn($n) => !str_ends_with($n, '.zip'))) === 0);
ok('it includes what this run wrote', in_array(basename($archiveA), $names, true));
ok('newest first', ($list[0]['mtime'] ?? 0) >= ($list[count($list) - 1]['mtime'] ?? PHP_INT_MAX));

@exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($archiveRoot));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
