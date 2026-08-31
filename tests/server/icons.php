<?php
/* PLAN_86 — copying a matched icon into a stack's own folder, and above all
 * its refusals: a bad reference, a cache miss, a name clash, an unwritable
 * folder. Server-only: it needs the plugin's icon helpers. Copy up and run:
 *
 *   php /tmp/icons.php
 *
 * Never touches a real stack folder: every case below hands
 * staxx_icon_adopt() an explicit /tmp directory to copy INTO, rather than
 * moving the store root — the same reason tests/server/pending.php gives for
 * avoiding that (moving it, even for one command, makes every real stack
 * vanish from the webGUI for as long as it is moved).
 *
 * staxx_icon_adopt() reads its SOURCE from the real shared icon cache
 * (staxx_icon_store_dir(), inside the data store's config folder since
 * PLAN_97 Phase 4) — that function takes no override, because there is
 * only one icon cache on a real server, unlike the stack root. This test
 * writes one throwaway file into that cache under a reference no real icon
 * collection entry uses ('staxx-selftest'), and removes it again on exit.
 * That is a few bytes in a shared cache, never a stack's own folder, and it
 * self-cleans even on failure. Needs a real, reachable data store — there
 * is nowhere to keep the fixture icon otherwise — so this aborts early with
 * a plain message rather than silently testing nothing if none is chosen.
 *
 * What this does NOT cover: staxx_icon_adopt_sweep()'s own walk over
 * staxx_scan_stacks(), because that reads the real store root with no way
 * to point it elsewhere short of moving it — precisely what this file
 * exists to avoid doing on a live server. What IS checked instead, at the
 * level reachable without moving anything, is the field the walk skips on:
 * staxx_compose_meta() correctly reporting a service's own recorded icon,
 * via a synthetic compose file under /tmp.
 *
 * Also covers PLAN_105: staxx_service_icon() no longer takes a stack-level
 * icon at all, so a service with none of its own now has only its image
 * name left to resolve from, and a service's own stated icon still wins.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';

if (!staxx_store_reachable()) {
  echo "FAIL   no reachable data store — set STORE_ROOT to a real, present folder before "
     . "running this suite; there is nowhere to keep the fixture icon otherwise\n";
  exit(1);
}

$fails = 0;
function check(string $what, bool $ok): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
}

$ref = 'staxx-selftest';

// The one write outside /tmp this file makes, and it cleans itself up
// however the run ends.
$cachePath = staxx_icon_store_dir().'/'.$ref.'.png';
register_shutdown_function(function () use ($cachePath) {
  @unlink($cachePath);
});

$pngBytes = "\x89PNG\r\n\x1a\nfake-but-good-enough-for-this-test";
@mkdir(staxx_icon_store_dir(), 0755, true);
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

// The picture lands in the stack's own hidden record folder, not loose beside
// the compose file, and what comes back is the relative path the compose file
// will name — './.staxx/<ref>.png', not a bare filename. This suite checked
// for a bare filename in the stack directory long after that stopped being
// true, and so reported three failures that were only ever its own.
$rel = './'.STAXX_RECORD_DIR.'/'.$ref.'.png';
$abs = $stack.'/'.STAXX_RECORD_DIR.'/'.$ref.'.png';

$error = '';
$file  = staxx_icon_adopt($ref, $stack, $error);
check("a fresh copy lands in the stack's own hidden folder",
  $file === $rel && is_file($abs));

check('the copy is byte-identical to the cached source',
  is_file($abs) && md5_file($abs) === md5($pngBytes));

$mtimeFirst = @filemtime($abs);

$error  = '';
$again  = staxx_icon_adopt($ref, $stack, $error);
check('running it a second time is a success',
  $again === $rel && $error === '');

check('and writes nothing the second time',
  @filemtime($abs) === $mtimeFirst);

/* ---- a different file already under that name ---- */

$clashDir  = $scratch.'/clash';
$clashPath = $clashDir.'/'.STAXX_RECORD_DIR.'/'.$ref.'.png';
@mkdir(dirname($clashPath), 0755, true);
file_put_contents($clashPath, 'not the same bytes at all');
$before = md5_file($clashPath);

$error = '';
check('a different file already under that name is refused',
  staxx_icon_adopt($ref, $clashDir, $error) === '' && $error !== '');

check('and is left completely untouched',
  md5_file($clashPath) === $before);

/* ---- PLAN_105 — the stack has no icon of its own ---- */
// staxx_service_icon() (in StacksTable.php, already required above) no
// longer takes a stack-icon argument at all, so there is no route left for
// a stack-level `icon:` field to reach a service — proven here directly
// against the chain rather than through a rendered page.

// Every part of this has to match nothing, service and stack name included:
// the search is not only on the image. An earlier version of this case used
// the service name "app", which matches the "app-store" icon and made the
// check fail for a reason that had nothing to do with what it was proving.
$none = staxx_service_icon('', $stack, 'zzqqxx/zzqqxx-nothing', 'zzqqxx-nothing', 'zzqqxx-nothing');
check('with no service icon, an image matching nothing resolves to nothing — no stack field is left to fall back to',
  $none['fa'] === '' && $none['url'] === '' && $none['ref'] === '');

$ownFa = staxx_service_icon('fa-server', $stack, 'zzstaxxtest/neverexisted-plan105', 'app', 'stack');
check('a service that states its own icon still wins, regardless of anything at stack level',
  $ownFa['fa'] === 'fa-server');

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
