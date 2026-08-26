<?php
/* PLAN_86 — copying a matched icon into a stack's own folder, and above all
 * its refusals: a bad reference, a cache miss, a name clash, an unwritable
 * folder. Server-only: it needs the plugin's icon helpers. Copy up and run:
 *
 *   php /tmp/icons.php
 *
 * Never touches a real stack folder: every case below hands
 * staxx_icon_adopt() an explicit /tmp directory to copy INTO, rather than
 * moving STACK_ROOT — the same reason tests/server/pending.php gives for
 * avoiding that (moving it, even for one command, makes every real stack
 * vanish from the webGUI for as long as it is moved).
 *
 * staxx_icon_adopt() reads its SOURCE from the real shared icon cache
 * (STAXX_ICON_STORE), because that constant is not overridable — there is
 * only one icon cache on a real server, unlike the stack root. This test
 * writes one throwaway file into that cache under a reference no real icon
 * collection entry uses ('staxx-selftest'), and removes it again on exit.
 * That is a few bytes in a shared cache, never a stack's own folder, and it
 * self-cleans even on failure.
 *
 * What this does NOT cover: staxx_icon_adopt_sweep()'s own walk over
 * staxx_scan_stacks(), because that reads the real STACK_ROOT with no way
 * to point it elsewhere short of moving it — precisely what this file
 * exists to avoid doing on a live server. What IS checked instead, at the
 * level reachable without moving anything, is the field the walk skips on:
 * staxx_compose_meta() correctly reporting a service's own recorded icon,
 * via a synthetic compose file under /tmp.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';

$fails = 0;
function check(string $what, bool $ok): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
}

$ref = 'staxx-selftest';

// The one write outside /tmp this file makes, and it cleans itself up
// however the run ends.
$cachePath = STAXX_ICON_STORE.'/'.$ref.'.png';
register_shutdown_function(function () use ($cachePath) {
  @unlink($cachePath);
});

$pngBytes = "\x89PNG\r\n\x1a\nfake-but-good-enough-for-this-test";
@mkdir(STAXX_ICON_STORE, 0755, true);
file_put_contents($cachePath, $pngBytes);

$scratch = '/tmp/staxx-icons-test';
@exec('rm -rf '.escapeshellarg($scratch));
$stack = $scratch.'/stack';
@mkdir($stack, 0755, true);

/* ---- the refusals ---- */

$error = '';
check('a ref that fails the safe-ref guard is refused',
  staxx_icon_adopt('../etc/passwd', $stack, $error) === '' && $error !== '');

$error = '';
check('a picture not in the cache yields no file and no crash, quietly',
  staxx_icon_adopt('staxx-nowhere-at-all', $stack, $error) === '' && $error !== ''
  && !is_file($stack.'/staxx-nowhere-at-all.png'));

$noDir = $scratch.'/does-not-exist';
$error = '';
check('an unwritable (non-existent) target directory is refused',
  staxx_icon_adopt($ref, $noDir, $error) === '' && $error !== '');

/* ---- the copy itself ---- */

$error = '';
$file  = staxx_icon_adopt($ref, $stack, $error);
check('a fresh copy lands in the target directory',
  $file === $ref.'.png' && is_file($stack.'/'.$file));

check('the copy is byte-identical to the cached source',
  is_file($stack.'/'.$file) && md5_file($stack.'/'.$file) === md5($pngBytes));

$mtimeFirst = @filemtime($stack.'/'.$file);

$error  = '';
$again  = staxx_icon_adopt($ref, $stack, $error);
check('running it a second time is a success',
  $again === $ref.'.png' && $error === '');

check('and writes nothing the second time',
  @filemtime($stack.'/'.$file) === $mtimeFirst);

/* ---- a different file already under that name ---- */

$clashDir = $scratch.'/clash';
@mkdir($clashDir, 0755, true);
$clashPath = $clashDir.'/'.$ref.'.png';
file_put_contents($clashPath, 'not the same bytes at all');
$before = md5_file($clashPath);

$error = '';
check('a different file already under that name is refused',
  staxx_icon_adopt($ref, $clashDir, $error) === '' && $error !== '');

check('and is left completely untouched',
  md5_file($clashPath) === $before);

/* ---- the field the walk relies on, never overwritten ---- */

$svcDir = $scratch.'/withicon';
@mkdir($svcDir, 0755, true);
file_put_contents($svcDir.'/compose.yaml',
  "services:\n  a:\n    image: busybox\n    x-unraid:\n      icon: something-already-here\n");

$meta = staxx_compose_meta($svcDir.'/compose.yaml');
check("a service's own recorded icon is what the walk reads before ever copying anything",
  $meta['ok'] && ($meta['services']['a']['x']['icon'] ?? '') === 'something-already-here');

echo "\n".($fails === 0 ? "all checks passed\n" : "$fails check(s) FAILED\n");
exit($fails === 0 ? 0 : 1);
