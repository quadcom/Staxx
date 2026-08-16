<?php
/* The link half of the companion-file helpers, which files.php cannot reach.
 *
 * Stacks live on /boot by default, which is vfat and cannot hold a symlink at
 * all — symlink() there simply fails, so every link case would pass for the
 * wrong reason. This run needs STACK_ROOT pointed at /tmp/b1-root instead,
 * which the CALLER sets and puts back; the script only reads the config and
 * refuses to run if the temporary root is not in place.
 *
 * Runs ON THE SERVER, and the caller must restore the config whatever happens:
 *
 *     CFG=/boot/config/plugins/stack.manager/stack.manager.cfg
 *     cp $CFG /tmp/cfg.bak
 *     sed -i 's#^STACK_ROOT=.*#STACK_ROOT="/tmp/b1-root"#' $CFG
 *     php /tmp/links.php; RC=$?
 *     cp /tmp/cfg.bak $CFG
 *     exit $RC
 *
 * What it is really guarding is stackman_rmtree(): a symlink in a stack folder
 * must be unlinked, never followed, or deleting a stack could delete a share.
 */

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

require_once '/usr/local/emhttp/plugins/stack.manager/include/Stacks.php';

$root = stackman_stack_root();
if ($root !== '/tmp/b1-root') {
  echo "FAIL   the temporary stack root is not in place (got $root)\n";
  exit(1);
}

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

ok('will not read through a link',  stackman_read_file($rel, 'filelink', $err) === null, $err);
ok('and says why',                  strpos($err, 'link') !== false, $err);
ok('will not write through a link', !stackman_write_file($rel, 'filelink', 'clobbered', true, $err), $err);
ok('the target is untouched', file_get_contents('/tmp/b1-outside/target.txt') === 'the link points here');

ok('deletes the link itself',       stackman_delete_file($rel, 'filelink', $err), $err);
ok('the link is gone',              !is_link($dir.'/filelink'));
ok('its target survives',           file_exists('/tmp/b1-outside/target.txt'));

/* Listing flags one rather than hiding it. */

$list  = stackman_list_files($rel, $err);
$found = null;
foreach ((array)$list as $e) if ($e['name'] === 'dirlink') $found = $e;
ok('a link is listed',              is_array($found));
ok('and flagged as a link',         ($found['link'] ?? false) === true);
ok('and never offered as text',     ($found['text'] ?? true) === false);

$extras = stackman_stack_extras($rel, $err);
$found  = null;
foreach ((array)$extras as $e) if ($e['name'] === 'dirlink') $found = $e;
ok('the delete list flags it too',  ($found['link'] ?? false) === true);
ok('and does not count through it', ($found['count'] ?? -1) === 0 && ($found['dir'] ?? true) === false);

/* And the whole-stack delete removes the link without walking through it. */

ok('confirmed delete succeeds',     stackman_delete_stack($rel, $err, true), $err);
ok('the stack folder is gone',      !is_dir($dir));
ok('the link did not take the folder it pointed at',
   is_dir('/tmp/b1-outside') && file_exists('/tmp/b1-outside/keep.txt'));

@exec('rm -rf /tmp/b1-root /tmp/b1-outside');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
