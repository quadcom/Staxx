<?php
/* The link half of the companion-file helpers, which files.php cannot reach.
 *
 * Stacks live on /boot by default, which is vfat and cannot hold a symlink at
 * all — symlink() there simply fails, so every link case would pass for the
 * wrong reason. This run needs STACK_ROOT pointed at /tmp/b1-root instead, and
 * ARCHIVE_ROOT at /tmp/b1-archives so the archive case has somewhere to write
 * that isn't the box's real appdata; the CALLER sets both and puts them back,
 * which the script only reads and refuses to run if either is not in place.
 *
 * Runs ON THE SERVER, and the caller must restore the config whatever happens:
 *
 *     CFG=/boot/config/plugins/staxx/staxx.cfg
 *     cp $CFG /tmp/cfg.bak
 *     sed -i 's#^STACK_ROOT=.*#STACK_ROOT="/tmp/b1-root"#' $CFG
 *     grep -q '^ARCHIVE_ROOT=' $CFG \
 *       && sed -i 's#^ARCHIVE_ROOT=.*#ARCHIVE_ROOT="/tmp/b1-archives"#' $CFG \
 *       || echo 'ARCHIVE_ROOT="/tmp/b1-archives"' >> $CFG
 *     php /tmp/links.php; RC=$?
 *     cp /tmp/cfg.bak $CFG
 *     exit $RC
 *
 * What it is really guarding is staxx_rmtree(): a symlink in a stack folder
 * must be unlinked, never followed, or archiving a stack could delete a share.
 * The one thing only this file (ext4, not vfat) can check on top of that is
 * that the symlink survives into the zip AS a link, not as whatever it points
 * at — a followed link into a share could make the archive enormous, or loop.
 */

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$root = staxx_stack_root();
if ($root !== '/tmp/b1-root') {
  echo "FAIL   the temporary stack root is not in place (got $root)\n";
  exit(1);
}
$archiveRoot = staxx_archive_root();
if ($archiveRoot !== '/tmp/b1-archives') {
  echo "FAIL   the temporary archive root is not in place (got $archiveRoot)\n";
  exit(1);
}
@exec('rm -rf '.escapeshellarg($archiveRoot));
mkdir($archiveRoot, 0755, true);

$rel = 'linky';
$dir = $root.'/'.$rel;
@exec('rm -rf /tmp/b1-root /tmp/b1-outside');
mkdir($dir, 0755, true);
file_put_contents($dir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

mkdir('/tmp/b1-outside', 0755, true);
file_put_contents('/tmp/b1-outside/keep.txt', 'must survive');
file_put_contents('/tmp/b1-outside/target.txt', 'the link points here');

$err = '';
ok('a symlink can be made here', symlink('/tmp/b1-outside', $dir.'/dirlink'));
ok('and one to a file', symlink('/tmp/b1-outside/target.txt', $dir.'/filelink'));

/* Reading, writing and deleting all refuse to follow one. */

ok('will not read through a link',  staxx_read_file($rel, 'filelink', $err) === null, $err);
ok('and says why',                  strpos($err, 'link') !== false, $err);
ok('will not write through a link', !staxx_write_file($rel, 'filelink', 'clobbered', true, $err), $err);
ok('the target is untouched', file_get_contents('/tmp/b1-outside/target.txt') === 'the link points here');

ok('deletes the link itself',       staxx_delete_file($rel, 'filelink', $err), $err);
ok('the link is gone',              !is_link($dir.'/filelink'));
ok('its target survives',           file_exists('/tmp/b1-outside/target.txt'));

/* Listing flags one rather than hiding it. */

$list  = staxx_list_files($rel, $err);
$found = null;
foreach ((array)$list as $e) if ($e['name'] === 'dirlink') $found = $e;
ok('a link is listed',              is_array($found));
ok('and flagged as a link',         ($found['link'] ?? false) === true);
ok('and never offered as text',     ($found['text'] ?? true) === false);

$extras = staxx_stack_extras($rel, $err);
$found  = null;
foreach ((array)$extras as $e) if ($e['name'] === 'dirlink') $found = $e;
ok('the delete list flags it too',  ($found['link'] ?? false) === true);
ok('and does not count through it', ($found['count'] ?? -1) === 0 && ($found['dir'] ?? true) === false);

/* And the whole-stack archive removes the link without walking through it —
 * and stores it as a link in the zip rather than following it in. Following
 * it would either pull the whole of /tmp/b1-outside into the archive under
 * "dirlink", or loop back on the stack folder itself were the target
 * inside it; storing it as a link keeps the zip to the size of the stack
 * folder, whatever the link points at. */

$archive = null;
ok('confirmed archive succeeds',    staxx_archive_stack($rel, $err, true, $archive), $err);
ok('the stack folder is gone',      !is_dir($dir));
ok('the link did not take the folder it pointed at',
   is_dir('/tmp/b1-outside') && file_exists('/tmp/b1-outside/keep.txt'));

$listing = staxx_sh('unzip -Z1 '.escapeshellarg($archive));
$lines   = explode("\n", trim($listing));
$link    = null;
foreach ($lines as $line) if (trim($line) === 'linky/dirlink') $link = $line;
ok('the archive lists the link by name', $link !== null);

// zipinfo's default listing leads with the Unix permission string — a stored
// symlink's starts with "l", exactly like `ls -l` would show for one on disk.
$info  = staxx_sh('zipinfo '.escapeshellarg($archive).' linky/dirlink');
$entry = '';
foreach (explode("\n", $info) as $line) if (strpos($line, 'dirlink') !== false) $entry = $line;
ok('and stores it as a symlink, not what it points at',
   preg_match('/^l/', trim($entry)) === 1, $entry);
ok('the link\'s target still holds its own files untouched',
   is_dir('/tmp/b1-outside') && file_exists('/tmp/b1-outside/target.txt'));

/* A stack folder that is itself a link — not just a link inside one — must
 * never be archived or removed: zip would store the link rather than the
 * target's contents, and the delete step underneath resolves through the
 * link and would empty out whatever it points at instead. */

$linkedRel = 'linkedstack';
$linkedDir = $root.'/'.$linkedRel;
mkdir('/tmp/b1-outside2', 0755, true);
file_put_contents('/tmp/b1-outside2/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents('/tmp/b1-outside2/keep.txt', 'must survive');
ok('a stack folder can itself be a link', symlink('/tmp/b1-outside2', $linkedDir));

ok('a linked stack is not listed', !in_array($linkedRel, array_column(staxx_list_stacks(), 'name')));

$archive2 = null;
ok('archiving a linked stack refuses', !staxx_archive_stack($linkedRel, $err, true, $archive2), $err);
ok('and says why',                     strpos($err, 'link') !== false, $err);
ok('the link is untouched',            is_link($linkedDir));
ok('its target still holds its files',
   is_dir('/tmp/b1-outside2') && file_exists('/tmp/b1-outside2/keep.txt'));

@exec('rm -rf /tmp/b1-outside2');

@exec('rm -rf /tmp/b1-root /tmp/b1-outside '.escapeshellarg($archiveRoot));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
