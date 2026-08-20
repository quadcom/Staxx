<?php
/* The companion-file helpers, checked against the real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/files.php root@<box>:/tmp/
 *     plink … "php /tmp/files.php"
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stack, "zzb1test", under whatever the stack root is. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
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

/* ------------------------------------------------------------- writing -- */

ok('writes a text file', staxx_write_file($rel, '.env', "A=1\r\nB=2\r\n", true, $err), $err);
ok('keeps CRLF',         file_get_contents($dir.'/.env') === "A=1\r\nB=2\r\n");
ok('leaves no temp file', !file_exists($dir.'/.env.staxx-tmp'));

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
ok('leaves no temp file after a refusal', !file_exists($dir.'/big.bin.staxx-tmp'));

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

/* -------------------------------------------------------------- delete -- */

ok('unconfirmed delete refuses', !staxx_delete_stack($rel, $err, false), $err);
ok('and says what is in the way', strpos($err, 'also contains') !== false, $err);
ok('nothing was removed', is_dir($dir) && file_exists($dir.'/sub/one.txt'));

ok('confirmed delete removes the lot', staxx_delete_stack($rel, $err, true), $err);
ok('the stack folder is gone', !is_dir($dir));

@exec('rm -rf '.escapeshellarg($dir));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
