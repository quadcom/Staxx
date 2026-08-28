<?php
/* staxx_make_path() and staxx_check_paths(), checked against the real
 * installed Stacks.php. PLAN_89 changed staxx_check_paths(): outside /mnt, a
 * path is now judged by where it is, not only by whether it exists — see the
 * allowlist and 'offroot' cases below.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. The relative-path
 * case near the end needs STACK_ROOT pointed at a scratch folder under /tmp,
 * so a stack root that happens to sit under /boot (the default) can be
 * proven not to leak the new off-root wording onto relative paths.
 * staxx_cfg() memoises on first read, so STACK_ROOT has to be seeded into
 * the config file BEFORE php runs, not changed from inside this script:
 *
 *     pscp tests/server/paths.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STACK_ROOT=" $CFG \
 *         && sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/zzb1test-paths-stackroot\"#" $CFG \
 *         || echo "STACK_ROOT=\"/tmp/zzb1test-paths-stackroot\"" >> $CFG
 *       php /tmp/paths.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       diff -q /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own folder, "zzb1test-paths", under /mnt/user/appdata — the
 * real Docker application data share, assumed to already exist on any box
 * that has installed an app through Community Applications. Also makes and
 * removes two folders under /tmp, outside /mnt: one to stand in for a
 * symlink's target, and one, "zzb1test-paths-offroot", to prove an
 * off-allowlist path is still flagged after something (Docker, in the real
 * bug) has already created it. The relative-path case makes its own stack,
 * "zzb1relstack", under the scratch STACK_ROOT above, and removes it before
 * the run ends. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

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

// The relative-path section near the end mkdir()s and rm -rf's a folder
// under the stack root. If the wrapper's STACK_ROOT seed did not take, that
// root is Adrian's real one — so refuse outright rather than risk it, before
// anything at all is created.
$relStackRoot = staxx_stack_root();
if ($relStackRoot !== '/tmp/zzb1test-paths-stackroot') {
  echo "FAIL   the temporary stack root is not in place (got $relStackRoot) — refusing to touch it\n";
  exit(1);
}

$base           = $appdata.'/zzb1test-paths';
$outside        = '/tmp/zzb1test-paths-outside';
$offrootScratch = '/tmp/zzb1test-paths-offroot';
$relStackDir    = $relStackRoot.'/zzb1relstack';
@exec('rm -rf '.escapeshellarg($base));
@exec('rm -rf '.escapeshellarg($outside));
@exec('rm -rf '.escapeshellarg($offrootScratch));
// Only ever the one stack folder inside it, never $relStackRoot itself — if
// the wrapper's STACK_ROOT seed did not take, $relStackRoot is Adrian's real
// stack root, and an rm -rf there would be catastrophic.
@exec('rm -rf '.escapeshellarg($relStackDir));

mkdir($base, 0751, true);
// Unraid's nobody:users, chosen deliberately unlike root (which is running
// this script), so inheritance actually proves something rather than
// matching by coincidence.
chown($base, 99);
chgrp($base, 100);
chmod($base, 0751); // mkdir()'s mode is filtered by umask; set it explicitly

$err = '';

/* ------------------------------------------------------------- never a share */

ok('refuses /mnt itself',       !staxx_make_path('/mnt', $err), $err);
ok('refuses /mnt/user',         !staxx_make_path('/mnt/user', $err), $err);
ok('refuses /mnt/user/appdata even though it already exists',
   !staxx_make_path($appdata, $err), $err);
ok('and says to make the share first', stripos($err, 'share') !== false, $err);

/* -------------------------------------------------------------- outside /mnt */

ok('refuses /etc/passwd',  !staxx_make_path('/etc/passwd', $err), $err);
ok('refuses a /tmp path',  !staxx_make_path('/tmp/x/y/z', $err), $err);
ok('refuses a path that climbs out of /mnt via ..',
   !staxx_make_path($appdata.'/../../../etc/x', $err), $err);

/* --------------------------------------------------------- symlinked ancestor */

mkdir($outside, 0755, true);
symlink($outside, $base.'/link');
ok('refuses through a symlink whose target is outside /mnt',
   !staxx_make_path($base.'/link/sub', $err), $err);
ok('nothing was made at the symlink target', !is_dir($outside.'/sub'));

/* --------------------------------------------------------------------- creation */

ok('makes two levels under an existing folder',
   staxx_make_path($base.'/a/b', $err), $err);
ok('both levels exist', is_dir($base.'/a') && is_dir($base.'/a/b'));

$sa = stat($base.'/a');
$sb = stat($base.'/a/b');
ok('the first level inherits owner and mode',
   $sa && $sa['uid'] === 99 && $sa['gid'] === 100 && ($sa['mode'] & 0777) === 0751);
ok('the second level inherits owner and mode too',
   $sb && $sb['uid'] === 99 && $sb['gid'] === 100 && ($sb['mode'] & 0777) === 0751);

ok('calling it again on the same path is a no-op, not an error',
   staxx_make_path($base.'/a/b', $err), $err);

/* ------------------------------------------------------------------ leaf is a file */

file_put_contents($base.'/isafile', 'x');
ok('refuses a leaf that already exists as a file',
   !staxx_make_path($base.'/isafile', $err), $err);
ok('and says it is a file, not a folder', strpos($err, 'not a folder') !== false, $err);

/* --------------------------------------------------------- staxx_check_paths() --
 *
 * The 'inuse' verdict — an existing, non-empty folder — only shows up when
 * the caller asks for it, which is the whole point: an existing stack's
 * volumes are full of its own data and must see no change at all. */

$emptyDir = $base.'/empty';
$fullDir  = $base.'/full';
mkdir($emptyDir, 0751, true);
mkdir($fullDir, 0751, true);
file_put_contents($fullDir.'/existing.conf', 'x');

$r1 = staxx_check_paths([$emptyDir], '', true);
ok('empty folder is "ok" with the flag on', $r1[$emptyDir] === 'ok', $r1[$emptyDir]);

$r2 = staxx_check_paths([$fullDir], '', true);
ok('non-empty folder is "inuse" with the flag on', $r2[$fullDir] === 'inuse', $r2[$fullDir]);

$r3 = staxx_check_paths([$fullDir], '', false);
ok('the same folder is "ok" with the flag off', $r3[$fullDir] === 'ok', $r3[$fullDir]);

$r4 = staxx_check_paths([$fullDir]); // default, unchanged for every existing call site
ok('and "ok" by default with no third argument at all', $r4[$fullDir] === 'ok', $r4[$fullDir]);

// missing / file / skipped are untouched by the flag either way — 'inuse'
// only ever replaces 'ok', nothing else.
$missingPath = $base.'/does-not-exist';
$filePath    = $base.'/isafile-check';
file_put_contents($filePath, 'x');
foreach ([true, false] as $flag) {
  $note = $flag ? 'flag on' : 'flag off';
  $r = staxx_check_paths([$missingPath, $filePath, '/etc/passwd'], '', $flag);
  ok("missing folder stays \"missing\" ($note)", $r[$missingPath] === 'missing', $r[$missingPath]);
  ok("a file stays \"file\" ($note)",            $r[$filePath] === 'file',       $r[$filePath]);
  // PLAN_89 inverts this one: /etc is on the allowlist and /etc/passwd
  // exists, so it is now reported rather than waved through unseen.
  ok("outside /mnt but allowlisted and existing is now \"ok\" ($note)",
     $r['/etc/passwd'] === 'ok', $r['/etc/passwd']);
}

/* -------------------------------------------------- staxx_check_paths() outside /mnt --
 *
 * PLAN_89: outside /mnt, a path is judged by where it is, not only by
 * whether it exists. A small allowlist of system locations stays silent
 * (the Docker socket, /etc/localtime, /sys/class/hwmon — real mounts on
 * Adrian's own stacks); everywhere else outside /mnt is 'offroot', whether
 * or not it exists — existence can't be trusted, because Docker creates a
 * missing bind-mount target on first start. */

$sockPath  = '/var/run/docker.sock';
$clockPath = '/etc/localtime';
$hwmonPath = '/sys/class/hwmon';
$rAllow = staxx_check_paths([$sockPath, $clockPath, $hwmonPath]);
ok('a socket outside /mnt on the allowlist is "ok"', $rAllow[$sockPath] === 'ok', $rAllow[$sockPath]);
ok('and specifically not "file" — the regression this guards',
   $rAllow[$sockPath] !== 'file', $rAllow[$sockPath]);
ok('a symlinked file outside /mnt on the allowlist is "ok"',
   $rAllow[$clockPath] === 'ok', $rAllow[$clockPath]);
ok('a directory outside /mnt on the allowlist is "ok"',
   $rAllow[$hwmonPath] === 'ok', $rAllow[$hwmonPath]);

$missingAllow = '/etc/zzb1test-missing-allowlisted';
$rMissingAllow = staxx_check_paths([$missingAllow]);
ok('a missing path under an allowlisted prefix is "missing"',
   $rMissingAllow[$missingAllow] === 'missing', $rMissingAllow[$missingAllow]);

$missingOffroot = '/appdata/zzb1test-paths-nope/deeper';
$rMissingOffroot = staxx_check_paths([$missingOffroot]);
ok('a missing path outside /mnt and off the allowlist is "offroot"',
   $rMissingOffroot[$missingOffroot] === 'offroot', $rMissingOffroot[$missingOffroot]);

mkdir($offrootScratch, 0755, true);
$rExistingOffroot = staxx_check_paths([$offrootScratch]);
ok('an EXISTING path outside /mnt and off the allowlist is still "offroot" — '.
   'proves the fix survives Docker having already created the folder',
   $rExistingOffroot[$offrootScratch] === 'offroot', $rExistingOffroot[$offrootScratch]);
@exec('rm -rf '.escapeshellarg($offrootScratch));

$bootPath = '/boot/config';
$rBoot = staxx_check_paths([$bootPath]);
ok('/boot is NOT allowlisted — an absolute /boot path is "offroot" even though it exists',
   $rBoot[$bootPath] === 'offroot', $rBoot[$bootPath]);

$nearMiss = '/etcetera';
$rNearMiss = staxx_check_paths([$nearMiss]);
ok('a near-miss beginning "/etc" does not match the allowlist by prefix',
   $rNearMiss[$nearMiss] === 'offroot', $rNearMiss[$nearMiss]);

$rInUseOutside = staxx_check_paths(['/etc'], '', true);
ok('isNew never turns an outside-/mnt path into "inuse", even a full directory',
   $rInUseOutside['/etc'] === 'ok', $rInUseOutside['/etc']);

/* ------------------------------ relative paths are untouched by any of this --
 *
 * STACK_ROOT defaults to a path under /boot, so on a default install every
 * relative volume path resolves under the flash drive. The allowlist above
 * is only for an ABSOLUTE path written as "/boot…" in the file — a relative
 * path must not pick up the flash wording just because its root happens to
 * live there. The wrapper in the header points STACK_ROOT at a /tmp folder
 * for this run so that can be proven without going near Adrian's real
 * stacks or the real flash drive. */

mkdir($relStackDir.'/data', 0755, true);
$rRel = staxx_check_paths(['./data', './does-not-exist'], 'zzb1relstack');
ok('a relative path under a flash-rooted stack still resolves normally: existing is "ok"',
   $rRel['./data'] === 'ok', $rRel['./data']);
ok('a relative path under a flash-rooted stack still resolves normally: missing is "missing"',
   $rRel['./does-not-exist'] === 'missing', $rRel['./does-not-exist']);
@exec('rm -rf '.escapeshellarg($relStackDir));

/* -------------------------------------------------------------------------- cleanup */

@exec('rm -rf '.escapeshellarg($base));
@exec('rm -rf '.escapeshellarg($outside));


/* ------------------------- what the folder picker offers for the stacks ----
 * A big server shows 25 folders at /mnt and only a handful are places the
 * stacks may live. They are MARKED rather than removed, so somebody hunting
 * for disk8 can see it and read why it is out — a missing row reads as a bug.
 *
 * Every case here is read-only: browsing lists directories, and the mkdir
 * cases below only ever exercise refusals, so nothing is created anywhere.
 */
/* Settings.php as well, because staxx_browse_dirs() reaches
 * staxx_path_in_memory() through function_exists() — that guard exists to
 * avoid a require loop, and it means a suite that loads only Stacks.php would
 * quietly test one rule fewer than the endpoint applies. The endpoint always
 * has this loaded, so the suite must too, and the rootshare case below is what
 * proves the guarded call actually fires. */
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';

$browse = staxx_browse_dirs('/mnt', 'stacks');
ok('browsing /mnt for the stacks answers at all', isset($browse['dirs']), $browse['error'] ?? '');

$blocked = $browse['blocked'] ?? [];
$open    = array_values(array_diff($browse['dirs'] ?? [], array_keys($blocked)));

// Every array disk and the array's own behind-the-share-layer path.
$missedDisks = [];
foreach (($browse['dirs'] ?? []) as $d) {
  if ((preg_match('/^disk[0-9]+$/', $d) || $d === 'user0') && !isset($blocked[$d])) $missedDisks[] = $d;
}
ok('every array disk is marked unavailable', $missedDisks === [], implode(',', $missedDisks));

foreach (['disks', 'remotes'] as $holder) {
  if (!in_array($holder, $browse['dirs'] ?? [], true)) {
    echo "SKIP   /mnt/$holder case — not present on this box\n";
    continue;
  }
  ok('the '.$holder.' mount folder is marked unavailable', isset($blocked[$holder]));
}

// Every reason has to say something, or the row is dimmed with no explanation
// and the whole point of showing it is lost.
$blank = 0;
foreach ($blocked as $why) { if (trim((string)$why) === '') $blank++; }
ok('every unavailable row carries a reason', $blank === 0, $blank.' blank');

/* A root whose filesystem lives in memory, which on Unraid is /mnt/rootshare
 * and anything left over that is not a real mount. Skipped where the box has
 * none, but where it has one this is also the case that proves the guarded
 * call above is wired up rather than silently absent. */
$inMemory = [];
foreach (($browse['dirs'] ?? []) as $d) {
  if (staxx_path_in_memory('/mnt/'.$d) && !isset($blocked[$d])) $inMemory[] = $d;
}
ok('a root living in memory is marked unavailable', $inMemory === [], implode(',', $inMemory));

// The share layer has to stay openable — it is the way through to the shares,
// even though choosing it directly is refused elsewhere.
ok('/mnt/user is still openable', in_array('user', $open, true), implode(',', $open));
ok('something is left to choose from', count($open) > 0, implode(',', $open));

// Only the top level is filtered. Deeper down the rules are about shares, and
// the destination box is the single voice on those.
$inside = staxx_browse_dirs('/mnt/user', 'stacks');
ok('below /mnt nothing is marked unavailable', ($inside['blocked'] ?? []) === []);

// Any other purpose — a container's volume path, the archive folder — sees the
// unfiltered list, because an array disk is an ordinary thing to mount.
$plain = staxx_browse_dirs('/mnt');
ok('without the stacks purpose nothing is marked at all', ($plain['blocked'] ?? []) === []);
ok('...and the same folders are still listed',
   count($plain['dirs'] ?? []) === count($browse['dirs'] ?? []));

/* Creating a folder must not invent a share. Two segments below /mnt is a
 * share's own root; anything shallower is where a new share would appear,
 * which Unraid then discovers with whatever defaults apply. Refusals only —
 * nothing is created by these. */
$err = '';
ok('the picker refuses to create a folder in /mnt/user',
   staxx_browse_mkdir('/mnt/user', 'zzpaths-probe', $err, 'stacks') === ''
   && strpos($err, 'new Unraid share') !== false, $err);

$poolRoot = '';
foreach (($browse['dirs'] ?? []) as $d) {
  if (!isset($blocked[$d]) && $d !== 'user') { $poolRoot = '/mnt/'.$d; break; }
}
if ($poolRoot === '') {
  echo "SKIP   pool-root create case — no pool is offered on this box\n";
} else {
  $err = '';
  ok('the picker refuses to create a folder at the top of a pool ('.$poolRoot.')',
     staxx_browse_mkdir($poolRoot, 'zzpaths-probe', $err, 'stacks') === ''
     && strpos($err, 'new Unraid share') !== false, $err);
}

// The refusal is scoped to the stacks purpose: the same picker fills in volume
// paths, where making a folder on a pool is ordinary. Aimed at a parent that
// does not exist, so the older "no such folder" refusal answers first and
// nothing is created either way — what is asserted is that the SHARE rule did
// not fire.
$err = '';
staxx_browse_mkdir('/mnt/zzpaths-no-such-root', 'zzpaths-probe', $err, '');
ok('the share rule does not fire for other purposes',
   strpos($err, 'new Unraid share') === false, $err);

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
