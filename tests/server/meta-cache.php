<?php
/* The on-disk memory PLAN_48 gives staxx_compose_meta() — one JSON file per
 * stack under STAXX_META_DIR, keyed on file contents plus STAXX_META_VERSION
 * — checked against the real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/meta-cache.php root@<box>:/tmp/
 *     plink … "php /tmp/meta-cache.php"
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own throwaway compose files under /tmp, and removes only the
 * cache entries those files' own paths hash to — nothing else under
 * STAXX_META_DIR is touched, since that directory also holds real stacks'
 * remembered answers on a live box. Needs a real `docker compose`; skips
 * (not fails) without one, since every case here is about what gets
 * remembered, and nothing is remembered when compose is not installed. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_compose_cmd() === '') {
  echo "SKIPPED — no docker compose on this machine, every case here needs a real one\n";
  exit(0);
}

/* A fresh PHP process per read, so every answer comes from STAXX_META_DIR or
 * a real `docker compose config` — never from the in-process static cache
 * staxx_compose_meta() already keeps, which would otherwise mask whether the
 * disk cache is doing anything at all. */
function meta_fresh(string $file): array {
  $php  = PHP_BINARY !== '' ? PHP_BINARY : 'php';
  $code = 'require_once "/usr/local/emhttp/plugins/staxx/include/Stacks.php"; '
        . 'echo json_encode(staxx_compose_meta($argv[1]));';
  $cmd  = escapeshellarg($php).' -r '.escapeshellarg($code).' -- '.escapeshellarg($file);
  $out  = shell_exec($cmd.' 2>&1');
  $json = json_decode((string)$out, true);
  return is_array($json) ? $json : ['ok' => false, 'error' => 'bad subprocess output: '.$out];
}

function cache_path_for(string $file): string {
  return STAXX_META_DIR.'/'.md5($file).'.json';
}

$dir = '/tmp/zze1metacache';
@exec('rm -rf '.escapeshellarg($dir));
mkdir($dir, 0755, true);

$main    = $dir.'/compose.yaml';
$cache1  = cache_path_for($main);
@unlink($cache1);

$imageA  = 'alpine:3.20';
$imageB  = 'alpine:3.21';
$compose = fn(string $image) => "services:\n  a:\n    image: $image\n";

file_put_contents($main, $compose($imageA));

/* -------------------------------------------------------- case 1: hits -- */

$first = meta_fresh($main);
ok('first call succeeds and reports the image', $first['ok'] === true
   && ($first['services']['a']['image'] ?? '') === $imageA, json_encode($first));
ok('...and writes a cache file', is_file($cache1));

// Corrupt the REMEMBERED ANSWER while leaving its key untouched, then read
// again. If the second read still shells out to compose it would report the
// real image ($imageA); reporting the planted one instead is proof the
// answer came from disk, not from asking compose a second time.
$stored = json_decode((string)file_get_contents($cache1), true);
$stored['meta']['services']['a']['image'] = 'PLANTED-FROM-CACHE';
file_put_contents($cache1, json_encode($stored));

$second = meta_fresh($main);
ok('an unchanged file is served from the cache file, not re-parsed',
   ($second['services']['a']['image'] ?? '') === 'PLANTED-FROM-CACHE', json_encode($second));

/* --------------------------------------------------- case 2: main edit -- */

file_put_contents($main, $compose($imageB));
$afterMainEdit = meta_fresh($main);
ok('editing the compose file invalidates the cache',
   ($afterMainEdit['services']['a']['image'] ?? '') === $imageB, json_encode($afterMainEdit));

/* ----------------------------------------------- case 3: override edit -- */

$override = $dir.'/compose.override.yaml';
file_put_contents($override, "services:\n  a:\n    image: $imageA\n");
$withOverride1 = meta_fresh($main);
ok('adding an override invalidates the cache and is honoured',
   ($withOverride1['services']['a']['image'] ?? '') === $imageA, json_encode($withOverride1));

file_put_contents($override, "services:\n  a:\n    image: $imageB\n");
$withOverride2 = meta_fresh($main);
ok('editing the override invalidates the cache',
   ($withOverride2['services']['a']['image'] ?? '') === $imageB, json_encode($withOverride2));

@unlink($override);

/* ----------------------------------------------------- case 4: .env edit -- */

// Reset to a file that reads its image from a variable, so a change to
// .env genuinely changes what compose reports.
file_put_contents($main, "services:\n  a:\n    image: \${IMG}\n");

file_put_contents($dir.'/.env', "IMG=$imageA\n");
$withEnv1 = meta_fresh($main);
ok('a stack with a settings file is read correctly',
   ($withEnv1['services']['a']['image'] ?? '') === $imageA, json_encode($withEnv1));

file_put_contents($dir.'/.env', "IMG=$imageB\n");
$withEnv2 = meta_fresh($main);
ok('editing .env invalidates the cache',
   ($withEnv2['services']['a']['image'] ?? '') === $imageB, json_encode($withEnv2));

@unlink($dir.'/.env');
file_put_contents($main, $compose($imageA));   // back to a plain file for what follows
@unlink($cache1);

/* --------------------------------------------- case 5: extends: is live -- */

$extendsDir = '/tmp/zze1metacache-extends';
@exec('rm -rf '.escapeshellarg($extendsDir));
mkdir($extendsDir, 0755, true);

file_put_contents($extendsDir.'/base.yaml', "services:\n  base:\n    image: $imageA\n");
$extendsMain  = $extendsDir.'/compose.yaml';
$extendsCache = cache_path_for($extendsMain);
file_put_contents($extendsMain,
  "services:\n  a:\n    extends:\n      file: base.yaml\n      service: base\n");

$extendsResult = meta_fresh($extendsMain);
ok('a file mentioning extends: still parses correctly',
   $extendsResult['ok'] === true, json_encode($extendsResult));
ok('...but is never written to the cache at all', !is_file($extendsCache));

@exec('rm -rf '.escapeshellarg($extendsDir));

/* ------------------------------------------------ case 6: version bump -- */

// A real version bump changes STAXX_META_VERSION, which changes every
// stack's key. Simulated here the same way it would happen in practice:
// the file on disk holds a key that no longer matches what the running
// code computes, so it must be treated as a miss regardless of why the
// keys differ.
meta_fresh($main);   // populate a real cache entry first
$stored = json_decode((string)file_get_contents($cache1), true);
$stored['key'] = 'not-a-real-key';
$stored['meta']['services']['a']['image'] = 'PLANTED-STALE-VERSION';
file_put_contents($cache1, json_encode($stored));

$afterVersionMismatch = meta_fresh($main);
ok('a key that no longer matches (e.g. a version bump) invalidates everything',
   ($afterVersionMismatch['services']['a']['image'] ?? '') === $imageA
   && ($afterVersionMismatch['services']['a']['image'] ?? '') !== 'PLANTED-STALE-VERSION',
   json_encode($afterVersionMismatch));

/* --------------------------------------------- case 7: compose rejects -- */

$badDir = '/tmp/zze1metacache-bad';
@exec('rm -rf '.escapeshellarg($badDir));
mkdir($badDir, 0755, true);
$badMain  = $badDir.'/compose.yaml';
$badCache = cache_path_for($badMain);
file_put_contents($badMain, "services:\n  a:\n    image:\n");   // no value — compose rejects this

$badResult = meta_fresh($badMain);
ok('a file compose rejects reports failure', $badResult['ok'] === false, json_encode($badResult));
ok('...and leaves nothing behind in the cache', !is_file($badCache));

@exec('rm -rf '.escapeshellarg($badDir));

/* ------------------------------------------- case 8: one file per stack -- */

@unlink($cache1);
$before = count(glob(STAXX_META_DIR.'/*.json') ?: []);
for ($i = 0; $i < 3; $i++) {
  file_put_contents($main, $compose("alpine:3.2$i"));
  meta_fresh($main);
}
$after = count(glob(STAXX_META_DIR.'/*.json') ?: []);
ok('three edits to the same stack write exactly one new cache file, not three',
   is_file($cache1) && $after === $before + 1, "before=$before after=$after");

/* ------------------------------------------------------------- cleanup -- */

@unlink($cache1);
@exec('rm -rf '.escapeshellarg($dir));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
