<?php
/* stackman_make_path() and stackman_check_paths(), checked against the real
 * installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/paths.php root@<box>:/tmp/
 *     plink … "php /tmp/paths.php"
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own folder, "zzb1test-paths", under /mnt/user/appdata — the
 * real Docker application data share, assumed to already exist on any box
 * that has installed an app through Community Applications. Also makes and
 * removes one folder under /tmp, outside /mnt, to stand in for a symlink's
 * target. */

require_once '/usr/local/emhttp/plugins/stack.manager/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$appdata = '/mnt/user/appdata';
if (!is_dir($appdata)) {
  echo "FAIL   $appdata does not exist on this box — nothing to test against\n";
  exit(1);
}

$base    = $appdata.'/zzb1test-paths';
$outside = '/tmp/zzb1test-paths-outside';
@exec('rm -rf '.escapeshellarg($base));
@exec('rm -rf '.escapeshellarg($outside));

mkdir($base, 0751, true);
// Unraid's nobody:users, chosen deliberately unlike root (which is running
// this script), so inheritance actually proves something rather than
// matching by coincidence.
chown($base, 99);
chgrp($base, 100);
chmod($base, 0751); // mkdir()'s mode is filtered by umask; set it explicitly

$err = '';

/* ------------------------------------------------------------- never a share */

ok('refuses /mnt itself',       !stackman_make_path('/mnt', $err), $err);
ok('refuses /mnt/user',         !stackman_make_path('/mnt/user', $err), $err);
ok('refuses /mnt/user/appdata even though it already exists',
   !stackman_make_path($appdata, $err), $err);
ok('and says to make the share first', stripos($err, 'share') !== false, $err);

/* -------------------------------------------------------------- outside /mnt */

ok('refuses /etc/passwd',  !stackman_make_path('/etc/passwd', $err), $err);
ok('refuses a /tmp path',  !stackman_make_path('/tmp/x/y/z', $err), $err);
ok('refuses a path that climbs out of /mnt via ..',
   !stackman_make_path($appdata.'/../../../etc/x', $err), $err);

/* --------------------------------------------------------- symlinked ancestor */

mkdir($outside, 0755, true);
symlink($outside, $base.'/link');
ok('refuses through a symlink whose target is outside /mnt',
   !stackman_make_path($base.'/link/sub', $err), $err);
ok('nothing was made at the symlink target', !is_dir($outside.'/sub'));

/* --------------------------------------------------------------------- creation */

ok('makes two levels under an existing folder',
   stackman_make_path($base.'/a/b', $err), $err);
ok('both levels exist', is_dir($base.'/a') && is_dir($base.'/a/b'));

$sa = stat($base.'/a');
$sb = stat($base.'/a/b');
ok('the first level inherits owner and mode',
   $sa && $sa['uid'] === 99 && $sa['gid'] === 100 && ($sa['mode'] & 0777) === 0751);
ok('the second level inherits owner and mode too',
   $sb && $sb['uid'] === 99 && $sb['gid'] === 100 && ($sb['mode'] & 0777) === 0751);

ok('calling it again on the same path is a no-op, not an error',
   stackman_make_path($base.'/a/b', $err), $err);

/* ------------------------------------------------------------------ leaf is a file */

file_put_contents($base.'/isafile', 'x');
ok('refuses a leaf that already exists as a file',
   !stackman_make_path($base.'/isafile', $err), $err);
ok('and says it is a file, not a folder', strpos($err, 'not a folder') !== false, $err);

/* --------------------------------------------------------- stackman_check_paths() --
 *
 * The 'inuse' verdict — an existing, non-empty folder — only shows up when
 * the caller asks for it, which is the whole point: an existing stack's
 * volumes are full of its own data and must see no change at all. */

$emptyDir = $base.'/empty';
$fullDir  = $base.'/full';
mkdir($emptyDir, 0751, true);
mkdir($fullDir, 0751, true);
file_put_contents($fullDir.'/existing.conf', 'x');

$r1 = stackman_check_paths([$emptyDir], '', true);
ok('empty folder is "ok" with the flag on', $r1[$emptyDir] === 'ok', $r1[$emptyDir]);

$r2 = stackman_check_paths([$fullDir], '', true);
ok('non-empty folder is "inuse" with the flag on', $r2[$fullDir] === 'inuse', $r2[$fullDir]);

$r3 = stackman_check_paths([$fullDir], '', false);
ok('the same folder is "ok" with the flag off', $r3[$fullDir] === 'ok', $r3[$fullDir]);

$r4 = stackman_check_paths([$fullDir]); // default, unchanged for every existing call site
ok('and "ok" by default with no third argument at all', $r4[$fullDir] === 'ok', $r4[$fullDir]);

// missing / file / skipped are untouched by the flag either way — 'inuse'
// only ever replaces 'ok', nothing else.
$missingPath = $base.'/does-not-exist';
$filePath    = $base.'/isafile-check';
file_put_contents($filePath, 'x');
foreach ([true, false] as $flag) {
  $note = $flag ? 'flag on' : 'flag off';
  $r = stackman_check_paths([$missingPath, $filePath, '/etc/passwd'], '', $flag);
  ok("missing folder stays \"missing\" ($note)", $r[$missingPath] === 'missing', $r[$missingPath]);
  ok("a file stays \"file\" ($note)",            $r[$filePath] === 'file',       $r[$filePath]);
  ok("outside /mnt stays \"skipped\" ($note)",   $r['/etc/passwd'] === 'skipped', $r['/etc/passwd']);
}

/* -------------------------------------------------------------------------- cleanup */

@exec('rm -rf '.escapeshellarg($base));
@exec('rm -rf '.escapeshellarg($outside));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
