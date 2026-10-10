<?php
/* ensure-resvg — StaXX's pinned copy of the resvg SVG drawer, used so emails
 * can show app icons as PNG. Runs ON THE SERVER and touches nothing real:
 * every case points STAXX_RESVG_TARGET, _CACHE, _URL and _SHA256 at a scratch
 * tree under /tmp this file creates and removes itself, with a fixture tar.gz
 * holding a tiny shell script named `resvg` standing in for the real binary.
 *
 *     pscp tests/server/resvg_ensure.php root@<box>:/tmp/
 *     plink … "php /tmp/resvg_ensure.php"
 *
 * Prints one line per case and exits non-zero on any failure. */

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

const FIXTURE_VERSION = 'v0.48.1'; // must match the script's own RESVG_VERSION

$script  = '/usr/local/emhttp/plugins/staxx/scripts/ensure-resvg';
$scratch = '/tmp/zz230-'.getmypid();
@exec('rm -rf '.escapeshellarg($scratch));
mkdir("$scratch/fixtures", 0755, true);
register_shutdown_function(function () use ($scratch) {
  @exec('rm -rf '.escapeshellarg($scratch));
});

$target  = "$scratch/target/resvg";
$cache   = "$scratch/cache";
$marker  = "$target.version";
$flash   = "$cache/resvg-".FIXTURE_VERSION.".tar.gz";

/* The fixture archive: one member, `resvg`, at the top level. */
file_put_contents("$scratch/fixtures/resvg", "#!/bin/sh\necho fixture\n");
$tgz = "$scratch/fixtures/resvg-linux-x86_64.tar.gz";
exec('tar -czf '.escapeshellarg($tgz).' -C '.escapeshellarg("$scratch/fixtures").' resvg');
$hash = hash_file('sha256', $tgz);
$url  = "file://$tgz";

function reset_dirs(string $scratch): void {
  @exec('rm -rf '.escapeshellarg("$scratch/target").' '.escapeshellarg("$scratch/cache"));
}
function run_script(string $script, array $env, string $arg = ''): array {
  $cmd = '';
  foreach ($env as $k => $v) $cmd .= $k.'='.escapeshellarg($v).' ';
  $cmd .= 'bash '.escapeshellarg($script);
  if ($arg !== '') $cmd .= ' '.escapeshellarg($arg);
  exec($cmd.' 2>&1', $lines, $rc);
  return [$rc, implode("\n", $lines)];
}
$env = fn(string $u, string $h) => [
  'STAXX_RESVG_TARGET' => $target, 'STAXX_RESVG_CACHE' => $cache,
  'STAXX_RESVG_URL' => $u, 'STAXX_RESVG_SHA256' => $h,
];

/* --- 1. fresh download installs ------------------------------------- */
reset_dirs($scratch);
[$rc, $out] = run_script($script, $env($url, $hash));
ok('download: exits 0', $rc === 0, $out);
ok('download: target is executable', is_executable($target));
ok('download: marker holds the version', is_file($marker) && trim(file_get_contents($marker)) === FIXTURE_VERSION);
ok('download: flash copy kept', is_file($flash));

/* --- 2. second run is a no-op --------------------------------------- */
$before = filemtime($target);
touch($target, $before - 100);
$before = filemtime($target);
[$rc, $out] = run_script($script, $env('file:///nonexistent', $hash));
ok('second run: exits 0 without the network', $rc === 0, $out);
ok('second run: target untouched', filemtime($target) === $before);

/* --- 3. flash copy used when the URL is unreachable ----------------- */
@unlink($target); @unlink($marker);
[$rc, $out] = run_script($script, $env('file:///nonexistent', $hash));
ok('flash copy: exits 0', $rc === 0, $out);
ok('flash copy: target reinstalled', is_executable($target) && is_file($marker));

/* --- 4. bad checksum installs nothing ------------------------------- */
reset_dirs($scratch);
[$rc, $out] = run_script($script, $env($url, str_repeat('0', 64)));
ok('bad checksum: exits non-zero', $rc !== 0, $out);
ok('bad checksum: nothing installed', !file_exists($target) && !file_exists($marker));
ok('bad checksum: nothing kept on flash', !file_exists($flash));

/* --- 5. an older version's flash copy is pruned --------------------- */
reset_dirs($scratch);
mkdir($cache, 0755, true);
$old = "$cache/resvg-v0.1.0.tar.gz";
file_put_contents($old, 'old');
[$rc, $out] = run_script($script, $env($url, $hash));
ok('prune: exits 0', $rc === 0, $out);
ok('prune: older flash copy removed, current kept', !file_exists($old) && is_file($flash));

/* --- 6. --remove clears target, marker and cache -------------------- */
[$rc, $out] = run_script($script, $env($url, $hash), '--remove');
ok('remove: exits 0', $rc === 0, $out);
ok('remove: target, marker and cache gone', !file_exists($target) && !file_exists($marker) && !file_exists($cache));

exit($fails > 0 ? 1 : 0);
