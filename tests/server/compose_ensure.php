<?php
/* ensure-compose — StaXX's own copy of Docker Compose, installed only when
 * `docker compose` does not already answer, and removed only when StaXX put
 * it there. See plans/PLAN_136-staxx-brings-its-own-compose.md.
 *
 * Runs ON THE SERVER, and touches nothing real: every case points
 * STAXX_COMPOSE_TARGET, STAXX_COMPOSE_CACHE and (bar case 9) STAXX_COMPOSE_URL
 * at a scratch tree under /tmp this file creates and removes itself, and
 * calls the plugin's own script through a fake `docker` this file writes,
 * never the real one. STAXX_COMPOSE_SHA256 is an override the script accepts
 * only so this suite can pin a small fixture instead of the real 75 MB
 * binary — production never sets it, and the pinned constant it defaults to
 * is duplicated below just for case 9's own assertion, not used to drive it.
 *
 *     pscp tests/server/compose_ensure.php root@<box>:/tmp/
 *     plink … "php /tmp/compose_ensure.php"
 *
 * Add STAXX_LIVE_COMPOSE=1 to also run one real download against the real
 * pinned version and constants (skipped otherwise, printed as such):
 *
 *     plink … "STAXX_LIVE_COMPOSE=1 php /tmp/compose_ensure.php"
 *
 * Prints one line per case and exits non-zero on any failure. */

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

// Duplicated from the script deliberately: case 9 checks a real download
// against this, it does not read it out of the script being tested.
const REAL_COMPOSE_VERSION = 'v2.40.3';
const REAL_COMPOSE_SHA256  = 'dba9d98e1ba5bfe11d88c99b9bd32fc4a0624a30fafe68eea34d61a3e42fd372';
const FIXTURE_VERSION      = 'v2.40.3'; // must match the script's own COMPOSE_VERSION constant

$script  = '/usr/local/emhttp/plugins/staxx/scripts/ensure-compose';
$scratch = '/tmp/zz136-'.getmypid();

@exec('rm -rf '.escapeshellarg($scratch));
mkdir($scratch, 0755, true);
register_shutdown_function(function () use ($scratch) {
  @exec('rm -rf '.escapeshellarg($scratch));
});

$target = "$scratch/target/docker-compose";
$cache  = "$scratch/cache";
$marker = "$target.staxx";
$flash  = "$cache/docker-compose-".FIXTURE_VERSION;

/* Fixture "binaries" — content is arbitrary, only the hash matters. */
$goodContent = "staxx-fixture-compose-binary\n";
$goodHash    = hash('sha256', $goodContent);
$badContent  = "not-the-right-bytes\n";

mkdir("$scratch/fixtures", 0755, true);
$fixtureGood = "$scratch/fixtures/good-download";
$fixtureBad  = "$scratch/fixtures/bad-download";
file_put_contents($fixtureGood, $goodContent);
file_put_contents($fixtureBad, $badContent);

/* Fake dockers. Each is called by the script as "$DOCKER_BIN compose version
 * --short", so each only has to answer that one invocation.
 *
 *  - docker-ok always answers, as if compose already worked.
 *  - docker-checks-target answers only once something executable sits at
 *    STAXX_COMPOSE_TARGET, which is what lets one case exercise both the
 *    initial "absent" check and the post-install verification honestly,
 *    the same way a real docker would see a plugin appear mid-run.
 *  - docker-always-fail never answers, even after an install.
 */
mkdir("$scratch/bin", 0755, true);
$dockerOk           = "$scratch/bin/docker-ok";
$dockerChecksTarget = "$scratch/bin/docker-checks-target";
$dockerAlwaysFail   = "$scratch/bin/docker-always-fail";

file_put_contents($dockerOk, "#!/bin/bash\n"
  . "if [ \"\$*\" = 'compose version --short' ]; then echo v9.9.9; exit 0; fi\n"
  . "exit 1\n");
file_put_contents($dockerChecksTarget, "#!/bin/bash\n"
  . "if [ \"\$*\" = 'compose version --short' ]; then\n"
  . "  if [ -x \"\$STAXX_COMPOSE_TARGET\" ]; then echo v9.9.9; exit 0; fi\n"
  . "  exit 1\n"
  . "fi\n"
  . "exit 1\n");
file_put_contents($dockerAlwaysFail, "#!/bin/bash\nexit 1\n");
chmod($dockerOk, 0755);
chmod($dockerChecksTarget, 0755);
chmod($dockerAlwaysFail, 0755);

function reset_dirs(string $scratch): void {
  @exec('rm -rf '.escapeshellarg("$scratch/target").' '.escapeshellarg("$scratch/cache"));
}

/* Runs the script under test with the given environment, returns
 * [exit code, combined stdout+stderr]. */
function run_script(string $script, array $env, string $arg = ''): array {
  $cmd = '';
  foreach ($env as $k => $v) {
    $cmd .= $k.'='.escapeshellarg($v).' ';
  }
  $cmd .= 'bash '.escapeshellarg($script);
  if ($arg !== '') $cmd .= ' '.escapeshellarg($arg);
  $cmd .= ' 2>&1';
  exec($cmd, $outLines, $rc);
  return [$rc, implode("\n", $outLines)];
}

function state_of(string $cache): string {
  $f = "$cache/state";
  return is_file($f) ? trim(file_get_contents($f)) : '';
}

$baseEnv = [
  'STAXX_COMPOSE_TARGET' => $target,
  'STAXX_COMPOSE_CACHE'  => $cache,
  'STAXX_COMPOSE_SHA256' => $goodHash,
];

/* --- 1. docker already answers: nothing touched --------------------- */
reset_dirs($scratch);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file:///should-not-be-fetched',
  'STAXX_DOCKER_BIN'  => $dockerOk,
]);
ok('present: exits 0', $rc === 0, $out);
ok('present: state records what docker reported', state_of($cache) === 'ok present v9.9.9', state_of($cache));
ok('present: target left untouched', !file_exists($target));

/* --- 2. absent, good flash copy: installs from flash, no network ----- */
reset_dirs($scratch);
mkdir($cache, 0755, true);
copy($fixtureGood, $flash);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file:///should-not-be-fetched',
  'STAXX_DOCKER_BIN'  => $dockerChecksTarget,
]);
ok('flash install: exits 0', $rc === 0, $out);
ok('flash install: target installed 0755', is_file($target) && (fileperms($target) & 0777) === 0755);
ok('flash install: marker names the version', is_file($marker) && trim(file_get_contents($marker)) === FIXTURE_VERSION);
ok('flash install: state says so', state_of($cache) === 'ok installed '.FIXTURE_VERSION.' flash', state_of($cache));

/* --- 3. absent, flash copy hash wrong: discarded, falls back to a good
 * download fixture, and the bad copy does not survive -------------------- */
reset_dirs($scratch);
mkdir($cache, 0755, true);
file_put_contents($flash, $badContent);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file://'.$fixtureGood,
  'STAXX_DOCKER_BIN'  => $dockerChecksTarget,
]);
ok('bad flash + good download: exits 0', $rc === 0, $out);
ok('bad flash + good download: state says download', state_of($cache) === 'ok installed '.FIXTURE_VERSION.' download', state_of($cache));
ok('bad flash + good download: flash copy replaced with the verified one', is_file($flash) && hash('sha256', file_get_contents($flash)) === $goodHash);
ok('bad flash + good download: target installed', is_file($target));

/* --- 4. absent, no flash copy, download fails to verify -------------- */
reset_dirs($scratch);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file://'.$fixtureBad,
  'STAXX_DOCKER_BIN'  => $dockerChecksTarget,
]);
ok('bad download hash: exits non-zero', $rc !== 0, $out);
ok('bad download hash: state starts fail', str_starts_with(state_of($cache), 'fail'), state_of($cache));
ok('bad download hash: nothing installed', !file_exists($target));
ok('bad download hash: no tmp files left behind', glob("$cache/download.*") === []);

/* --- 5. absent, no flash copy, download unreachable ------------------ */
reset_dirs($scratch);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file:///no/such/fixture-xyz',
  'STAXX_DOCKER_BIN'  => $dockerChecksTarget,
]);
ok('unreachable download: exits non-zero', $rc !== 0, $out);
ok('unreachable download: wording distinguishes "could not be reached"', str_contains(state_of($cache), 'could not be reached'), state_of($cache));
ok('unreachable download: nothing installed', !file_exists($target));

/* --- 6. --remove, marker present: takes its own back ------------------ */
reset_dirs($scratch);
mkdir(dirname($target), 0755, true);
file_put_contents($target, 'x');
file_put_contents($marker, FIXTURE_VERSION."\n");
mkdir($cache, 0755, true);
file_put_contents("$cache/state", "ok installed ".FIXTURE_VERSION." flash\n");
[$rc, $out] = run_script($script, ['STAXX_COMPOSE_TARGET' => $target, 'STAXX_COMPOSE_CACHE' => $cache], '--remove');
ok('remove with marker: exits 0', $rc === 0, $out);
ok('remove with marker: target gone', !file_exists($target));
ok('remove with marker: marker gone', !file_exists($marker));
ok('remove with marker: cache gone', !is_dir($cache));

/* --- 7. --remove, no marker: leaves someone else's compose alone ------ */
reset_dirs($scratch);
mkdir(dirname($target), 0755, true);
file_put_contents($target, 'x');
mkdir($cache, 0755, true);
file_put_contents("$cache/leftover", 'x');
[$rc, $out] = run_script($script, ['STAXX_COMPOSE_TARGET' => $target, 'STAXX_COMPOSE_CACHE' => $cache], '--remove');
ok('remove without marker: exits 0', $rc === 0, $out);
ok('remove without marker: target left in place', file_exists($target));
ok('remove without marker: cache still removed', !is_dir($cache));

/* --- 8. installed, but docker never comes to see it ------------------- */
reset_dirs($scratch);
mkdir($cache, 0755, true);
copy($fixtureGood, $flash);
[$rc, $out] = run_script($script, $baseEnv + [
  'STAXX_COMPOSE_URL' => 'file:///should-not-be-fetched',
  'STAXX_DOCKER_BIN'  => $dockerAlwaysFail,
]);
ok('installed but unseen: exits non-zero', $rc !== 0, $out);
ok('installed but unseen: state says Docker does not see it', str_contains(state_of($cache), 'does not see it'), state_of($cache));

/* --- 9. opt-in: one real download against the real pinned version ----- */
if (getenv('STAXX_LIVE_COMPOSE') === '1') {
  reset_dirs($scratch);
  [$rc, $out] = run_script($script, [
    'STAXX_COMPOSE_TARGET' => $target,
    'STAXX_COMPOSE_CACHE'  => $cache,
    'STAXX_DOCKER_BIN'     => $dockerChecksTarget,
    // No STAXX_COMPOSE_URL or STAXX_COMPOSE_SHA256 override: this is the
    // one case that proves the real pinned release still matches.
  ]);
  ok('live download: exits 0', $rc === 0, $out);
  ok('live download: state records the real version', state_of($cache) === 'ok installed '.REAL_COMPOSE_VERSION.' download', state_of($cache));
  ok('live download: installed file matches the pinned checksum',
     is_file($target) && hash_file('sha256', $target) === REAL_COMPOSE_SHA256);
} else {
  echo "skipped  live download against the real pinned release (set STAXX_LIVE_COMPOSE=1 to run it)\n";
}

exit($fails > 0 ? 1 : 0);
