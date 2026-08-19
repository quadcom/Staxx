<?php
/* The two-file (main + override) compose support: staxx_compose_files(),
 * staxx_compose_file_args(), the strict pairing rule, and how it feeds
 * staxx_stack_extras(), staxx_delete_stack() and staxx_validate_compose().
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Stacks live on
 * /boot by default, so this run needs STACK_ROOT pointed at /tmp instead —
 * the CALLER sets that and puts the config back, same as links.php:
 *
 *     CFG=/boot/config/plugins/staxx/staxx.cfg
 *     cp $CFG /tmp/cfg.bak
 *     sed -i 's#^STACK_ROOT=.*#STACK_ROOT="/tmp/b1-override"#' $CFG
 *     php /tmp/override.php; RC=$?
 *     cp /tmp/cfg.bak $CFG
 *     exit $RC
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks under whatever the stack root is; the three
 * staxx_validate_compose() cases need a real `docker compose` and are
 * skipped, not failed, if this box has none.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$root = staxx_stack_root();
if ($root !== '/tmp/b1-override') {
  echo "FAIL   the temporary stack root is not in place (got $root)\n";
  exit(1);
}

/* --------------------------------------------------- staxx_compose_files -- */

ok("staxx_compose_files('') is empty", staxx_compose_files('') === []);

$rel  = 'zze1pair';
$dir  = $root.'/'.$rel;
@exec('rm -rf '.escapeshellarg($dir));
mkdir($dir, 0755, true);

$compose = "services:\n  a:\n    image: alpine:3.20\n";

// ---- single file: the identity every existing caller relies on ----

file_put_contents($dir.'/compose.yaml', $compose);
$single = staxx_compose_files($dir.'/compose.yaml');
ok('a lone compose.yaml is one entry', $single === [$dir.'/compose.yaml'], json_encode($single));
ok('...and its -f args are exactly the old inline string, character for character',
   staxx_compose_file_args($single) === '-f '.escapeshellarg($dir.'/compose.yaml'));

// ---- compose.yaml + compose.override.yaml: paired, main first ----

file_put_contents($dir.'/compose.override.yaml', $compose);
$pair = staxx_compose_files($dir.'/compose.yaml');
ok('compose.yaml + compose.override.yaml pairs up', count($pair) === 2, json_encode($pair));
ok('...main is first', ($pair[0] ?? '') === $dir.'/compose.yaml');
ok('...override is second', ($pair[1] ?? '') === $dir.'/compose.override.yaml');
@unlink($dir.'/compose.override.yaml');

// ---- compose.yaml + compose.override.yml: the other extension pairs too ----

file_put_contents($dir.'/compose.override.yml', $compose);
$pairYml = staxx_compose_files($dir.'/compose.yaml');
ok('compose.yaml + compose.override.yml pairs up too',
   $pairYml === [$dir.'/compose.yaml', $dir.'/compose.override.yml'], json_encode($pairYml));
@unlink($dir.'/compose.override.yml');

// ---- strict pairing: the wrong basename is never picked up ----

file_put_contents($dir.'/docker-compose.override.yml', $compose);
$strict1 = staxx_compose_files($dir.'/compose.yaml');
ok('compose.yaml does NOT pair with docker-compose.override.yml',
   $strict1 === [$dir.'/compose.yaml'], json_encode($strict1));
@unlink($dir.'/docker-compose.override.yml');

@rename($dir.'/compose.yaml', $dir.'/docker-compose.yml');
file_put_contents($dir.'/compose.override.yaml', $compose);
$strict2 = staxx_compose_files($dir.'/docker-compose.yml');
ok('docker-compose.yml does NOT pair with compose.override.yaml',
   $strict2 === [$dir.'/docker-compose.yml'], json_encode($strict2));
@unlink($dir.'/compose.override.yaml');
@rename($dir.'/docker-compose.yml', $dir.'/compose.yaml');

// ---- a filename that merely contains the word "override" is never paired --

file_put_contents($dir.'/override.yaml', $compose);
$notWord1 = staxx_compose_files($dir.'/compose.yaml');
ok('a sibling file just called override.yaml is not treated as one',
   $notWord1 === [$dir.'/compose.yaml'], json_encode($notWord1));
@unlink($dir.'/override.yaml');

file_put_contents($dir.'/my.override.notes', $compose);
$notWord2 = staxx_compose_files($dir.'/compose.yaml');
ok('a sibling file called my.override.notes is not treated as one either',
   $notWord2 === [$dir.'/compose.yaml'], json_encode($notWord2));
@unlink($dir.'/my.override.notes');

/* ---------------------------------------------- staxx_compose_file_args -- */

$args = staxx_compose_file_args([$dir.'/compose.yaml', $dir.'/compose.override.yaml']);
ok('two files produce two -f flags, main then override, each shell-quoted',
   $args === '-f '.escapeshellarg($dir.'/compose.yaml').' -f '.escapeshellarg($dir.'/compose.override.yaml'),
   $args);

// A path holding a single quote must still come out escaped — the file need
// not exist for this one, staxx_compose_file_args() only ever quotes what
// staxx_compose_files() hands it.
$hostilePair = ["/tmp/it's/compose.yaml", "/tmp/it's/compose.override.yaml"];
$hostileArgs = staxx_compose_file_args($hostilePair);
ok('a path holding a single quote survives, still quoted',
   $hostileArgs === '-f '.escapeshellarg($hostilePair[0]).' -f '.escapeshellarg($hostilePair[1]),
   $hostileArgs);

/* -------------------------------------------------- staxx_stack_extras -- */

file_put_contents($dir.'/compose.override.yaml', $compose);                 // the real, paired override
file_put_contents($dir.'/docker-compose.override.yml', 'unrelated: true');  // NOT paired — override-shaped only

$err = '';
$extras = staxx_stack_extras($rel, $err);
ok('staxx_stack_extras() runs clean', $extras !== null, $err);
$extraNames = array_column((array)$extras, 'name');
ok('the paired override is NOT listed as an extra file',
   !in_array('compose.override.yaml', $extraNames, true), implode(', ', $extraNames));
ok('the unrelated override-shaped file IS listed as an extra file',
   in_array('docker-compose.override.yml', $extraNames, true), implode(', ', $extraNames));

/* ------------------------------------------------- staxx_delete_stack -- */

$err = '';
$deleted = staxx_delete_stack($rel, $err, false);
ok('an unconfirmed delete refuses, because of the unrelated file',
   $deleted === false, $err);
ok('...naming the unrelated file', strpos($err, 'docker-compose.override.yml') !== false, $err);
ok('...and NOT naming the paired override', strpos($err, 'compose.override.yaml') === false, $err);

@unlink($dir.'/docker-compose.override.yml');
@exec('rm -rf '.escapeshellarg($dir));

/* ------------------------------------------- staxx_validate_compose -- */

$cmd = staxx_compose_cmd();
if ($cmd === '') {
  ok('SKIPPED — no docker compose on this machine, the $before/$after cases need a real one', true);
} else {
  $validDir = $root.'/zze1valid';
  @exec('rm -rf '.escapeshellarg($validDir));
  mkdir($validDir, 0755, true);

  // Unchanged behaviour: both empty, a valid file passes and an invalid one
  // fails, exactly as before this feature existed.
  $err = ''; $warnings = null;
  ok('a valid single file passes with $before/$after both empty',
     staxx_validate_compose($compose, $err, $validDir, $warnings), $err);

  $err = ''; $warnings = null;
  ok('an invalid single file still fails with $before/$after both empty',
     !staxx_validate_compose("services:\n  a:\n    image:\n", $err, $validDir, $warnings), $err);

  // The whole reason this exists: a main file that is broken alone (no
  // image at all) becomes valid once its override supplies the missing bit.
  $mainBroken   = "services:\n  a: {}\n";
  $overridePath = $validDir.'/compose.override.yaml';
  file_put_contents($overridePath, "services:\n  a:\n    image: alpine:3.20\n");

  $err = ''; $warnings = null;
  ok('a main file invalid alone PASSES once its override is layered on as $after',
     staxx_validate_compose($mainBroken, $err, $validDir, $warnings, '', $overridePath), $err);

  // Checking the override itself: the real main file goes in $before, and an
  // error has to say "your override file", not "your compose file".
  $mainPath = $validDir.'/compose.yaml';
  file_put_contents($mainPath, $compose);

  // A wrong TYPE, not a missing value: compose drops a null when it merges,
  // so `image:` with nothing after it is silently cancelled by the main
  // file's own image and the pair comes out valid. `ports` as a number
  // cannot be cancelled by anything the main file says.
  $err = ''; $warnings = null;
  $overrideBad = "services:\n  a:\n    ports: 8080\n";
  $passed = staxx_validate_compose($overrideBad, $err, $validDir, $warnings, $mainPath, '');
  ok('an invalid override, checked with the main file as $before, fails',
     !$passed, $err);
  ok('...and the message says "your override file", not "your compose file"',
     strpos($err, 'your override file') !== false && strpos($err, 'your compose file') === false, $err);

  @exec('rm -rf '.escapeshellarg($validDir));
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
